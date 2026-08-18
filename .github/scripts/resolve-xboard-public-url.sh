#!/usr/bin/env bash
set -Eeuo pipefail

mapfile -t caddy_configs < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- '127\.0\.0\.1:700[123]' /etc/caddy 2>/dev/null || true
)
if ((${#caddy_configs[@]} != 1)); then
  echo "PUBLIC_URL_FAIL=ambiguous_caddy_file count=${#caddy_configs[@]}" >&2
  exit 1
fi

mapfile -t blue_ids < <(docker ps -q --filter label=com.docker.compose.service=xboard)
if ((${#blue_ids[@]} != 1)); then
  echo "PUBLIC_URL_FAIL=blue_missing count=${#blue_ids[@]}" >&2
  exit 1
fi

caddy validate --config "${caddy_configs[0]}" --adapter caddyfile >/dev/null
caddy adapt --config "${caddy_configs[0]}" --adapter caddyfile 2>/dev/null | \
  docker exec -i "${blue_ids[0]}" php -r '
$config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$candidates = [];
foreach (($config["apps"]["http"]["servers"] ?? []) as $server) {
    $scheme = empty($server["tls_connection_policies"]) ? "http" : "https";
    $visit = function (mixed $node) use (&$visit, &$candidates, $scheme): void {
        if (! is_array($node)) {
            return;
        }
        if (isset($node["host"]) && is_array($node["host"])) {
            foreach ($node["host"] as $host) {
                if (is_string($host) && preg_match("/\\A[A-Za-z0-9.-]+\\z/", $host) === 1) {
                    $candidates[$scheme . "://" . $host] = true;
                }
            }
        }
        foreach ($node as $value) {
            $visit($value);
        }
    };
    $visit($server["routes"] ?? []);
}
$urls = array_keys($candidates);
sort($urls);
if (count($urls) !== 1) {
    fwrite(STDERR, "PUBLIC_URL_FAIL=ambiguous_caddy_origin count=" . count($urls) . PHP_EOL);
    exit(1);
}
echo $urls[0];
'
