#!/usr/bin/env bash
set -Eeuo pipefail

export PATH="${PATH:+$PATH:}/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

if ! declare -F xboard_find_caddy_upstream >/dev/null ||
   ! declare -F release_assert_cleanup_admission >/dev/null ||
   ! declare -F v2_find_workdir >/dev/null; then
  script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # shellcheck source=production-runtime-discovery.sh
  source "$script_dir/production-runtime-discovery.sh"
  # shellcheck source=release-state.sh
  source "$script_dir/release-state.sh"
  # shellcheck source=v2-low-memory-common.sh
  source "$script_dir/v2-low-memory-common.sh"
fi

converge_sub_fail() {
  local reason=$1
  echo "CONVERGE_SUB_GATEWAY_FAIL=$reason" >&2
  return 1
}

: "${EXPECTED_WORKFLOW_SHA:?EXPECTED_WORKFLOW_SHA is required}"
: "${SUBSCRIPTION_GATEWAY_URL:=https://sub.thinderbox.uk}"
: "${SUBSCRIPTION_ORIGIN_URL:=https://cloud.thinderbox.com}"
: "${CONVERGE_SUB_GATEWAY_TEST_MODE:=false}"
: "${CONVERGE_SUB_GATEWAY_SKIP_LOCK:=false}"

[[ "$EXPECTED_WORKFLOW_SHA" =~ ^[0-9a-f]{40}$ ]] || converge_sub_fail invalid_expected_workflow_sha
[[ "$SUBSCRIPTION_GATEWAY_URL" =~ ^https://[A-Za-z0-9.-]+$ ]] || converge_sub_fail invalid_gateway_url
[[ "$SUBSCRIPTION_ORIGIN_URL" =~ ^https://[A-Za-z0-9.-]+$ ]] || converge_sub_fail invalid_origin_url

if [[ "$CONVERGE_SUB_GATEWAY_TEST_MODE" != true ]]; then
  ((EUID == 0)) || converge_sub_fail root_required
fi

for tool in curl docker flock mktemp realpath; do
  command -v "$tool" >/dev/null 2>&1 || converge_sub_fail "tool_missing:$tool"
done

if [[ "$CONVERGE_SUB_GATEWAY_TEST_MODE" != true || "$CONVERGE_SUB_GATEWAY_SKIP_LOCK" != true ]]; then
  v2_find_workdir
  v2_acquire_lock
  release_assert_cleanup_admission "$V2_WORKDIR"
fi

probe_file=''
cleanup_converge_sub() {
  rm -f -- "$probe_file"
}
trap cleanup_converge_sub EXIT

if ! xboard_find_caddy_upstream; then
  converge_sub_fail "caddy_discovery_failed:${XBOARD_DISCOVERY_ERROR:-unknown}"
fi
if ! xboard_resolve_active_runtime "$XBOARD_ACTIVE_PORT"; then
  converge_sub_fail "runtime_discovery_failed:${XBOARD_DISCOVERY_ERROR:-unknown}"
fi
[[ "${XBOARD_ACTIVE_TOPOLOGY:-}" == v2 && -n "${XBOARD_ACTIVE_WEB:-}" ]] ||
  converge_sub_fail active_topology_not_v2

active_web_revision=$(xboard_container_label "$XBOARD_ACTIVE_WEB" org.opencontainers.image.revision)
[[ "$active_web_revision" == "$EXPECTED_WORKFLOW_SHA" ]] ||
  converge_sub_fail active_web_revision_mismatch

health_body=$(curl --silent --show-error --fail --max-time 10 "${SUBSCRIPTION_GATEWAY_URL}/__sub_gateway_healthz__" 2>/dev/null || true)
[[ "$health_body" == "OK" ]] || converge_sub_fail gateway_healthz_failed

root_status=$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 "${SUBSCRIPTION_GATEWAY_URL}/" || true)
[[ "$root_status" == "403" ]] || converge_sub_fail "gateway_root_not_isolated:$root_status"

probe_file=$(mktemp)
probe_status=$(
  curl --silent --output "$probe_file" --write-out '%{http_code}' --max-time 15 \
    -H 'Accept: application/json' \
    "${SUBSCRIPTION_GATEWAY_URL}/s/__gateway_origin_probe__" || true
)
[[ "$probe_status" == "403" ]] && grep -Fq '"token is error"' "$probe_file" ||
  converge_sub_fail "gateway_origin_proxy_probe_failed:$probe_status"

db_result=$(
  docker exec -i \
    -e SUBSCRIPTION_GATEWAY_URL="$SUBSCRIPTION_GATEWAY_URL" \
    -e SUBSCRIPTION_ORIGIN_URL="$SUBSCRIPTION_ORIGIN_URL" \
    "$XBOARD_ACTIVE_WEB" php <<'PHPEOF'
<?php
declare(strict_types=1);

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubscribeTemplate;
use App\Protocols\SingBox;
use App\Support\AbstractProtocol;
use Symfony\Component\Yaml\Yaml;

$gatewayUrl = rtrim(trim((string) getenv('SUBSCRIPTION_GATEWAY_URL')), '/');
$originUrl = rtrim(trim((string) getenv('SUBSCRIPTION_ORIGIN_URL')), '/');
$appUrl = rtrim(trim((string) admin_setting('app_url', '')), '/');
$currentSubscribe = rtrim(trim((string) admin_setting('subscribe_url', '')), '/');

$allowedPrevious = array_values(array_unique(array_filter(
    ['', $originUrl, $appUrl, $gatewayUrl],
    static fn(string $item): bool => true
)));

if (!in_array($currentSubscribe, $allowedPrevious, true)) {
    fwrite(STDERR, "CONVERGE_SUB_DB_FAIL=unexpected_existing_subscribe_url\n");
    exit(1);
}

$subscribeUrlUpdated = false;
if ($currentSubscribe !== $gatewayUrl) {
    admin_setting(['subscribe_url' => $gatewayUrl]);
    $subscribeUrlUpdated = true;
} else {
    // Ensure Redis settings cache is fresh across all Octane workers.
    admin_setting(['subscribe_url' => $gatewayUrl]);
}

$verifiedSubscribe = rtrim(trim((string) admin_setting('subscribe_url', '')), '/');
if ($verifiedSubscribe !== $gatewayUrl) {
    fwrite(STDERR, "CONVERGE_SUB_DB_FAIL=subscribe_url_verification_failed\n");
    exit(1);
}

$templatesUpdated = 0;

$singboxRaw = SubscribeTemplate::where('name', 'singbox')->value('content');
if (is_string($singboxRaw) && trim($singboxRaw) !== '') {
    $decoded = json_decode($singboxRaw, true);
    if (is_array($decoded)) {
        $sanitized = SingBox::sanitizeMainlandRuleSetConfig($decoded);
        if ($sanitized !== $decoded) {
            $encoded = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                fwrite(STDERR, "CONVERGE_SUB_DB_FAIL=singbox_json_encode_failed\n");
                exit(1);
            }
            SubscribeTemplate::setContent('singbox', $encoded);
            $templatesUpdated++;
        } else {
            SubscribeTemplate::flushCache('singbox');
        }
    }
}

foreach (['clash', 'clashmeta', 'stash'] as $tplName) {
    $raw = SubscribeTemplate::where('name', $tplName)->value('content');
    if (!is_string($raw) || trim($raw) === '') {
        SubscribeTemplate::flushCache($tplName);
        continue;
    }
    try {
        $parsed = Yaml::parse($raw);
    } catch (\Throwable) {
        SubscribeTemplate::flushCache($tplName);
        continue;
    }
    if (!is_array($parsed)) {
        SubscribeTemplate::flushCache($tplName);
        continue;
    }
    $sanitized = AbstractProtocol::sanitizeClashFakeIpBootstrapDns($parsed);
    if ($sanitized !== $parsed) {
        SubscribeTemplate::setContent($tplName, Yaml::dump($sanitized, 6, 2));
        $templatesUpdated++;
    } else {
        SubscribeTemplate::flushCache($tplName);
    }
}

printf(
    "CONVERGE_SUB_DB=PASS subscribe_url_updated=%s templates_updated=%d\n",
    $subscribeUrlUpdated ? 'true' : 'false',
    $templatesUpdated
);
PHPEOF
) || converge_sub_fail laravel_convergence_failed

sub_updated=$(sed -nE 's/^CONVERGE_SUB_DB=PASS subscribe_url_updated=(true|false) templates_updated=([0-9]+)$/\1/p' <<<"$db_result")
tpl_updated=$(sed -nE 's/^CONVERGE_SUB_DB=PASS subscribe_url_updated=(true|false) templates_updated=([0-9]+)$/\2/p' <<<"$db_result")
[[ "$sub_updated" == true || "$sub_updated" == false ]] || converge_sub_fail invalid_db_result
[[ "$tpl_updated" =~ ^[0-9]+$ ]] || converge_sub_fail invalid_template_count

echo "CONVERGE_SUBSCRIPTION_GATEWAY=PASS gateway=$SUBSCRIPTION_GATEWAY_URL active_web=$XBOARD_ACTIVE_WEB subscribe_url_updated=$sub_updated templates_updated=$tpl_updated"
