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

converge_host_fail() {
  local reason=$1
  echo "CONVERGE_HOST_FAIL=$reason" >&2
  return 1
}

: "${EXPECTED_WORKFLOW_SHA:?EXPECTED_WORKFLOW_SHA is required}"
: "${SUBSCRIPTION_GATEWAY_TRUSTED_CIDR:=103.133.179.159/32}"
: "${XBOARD_CADDY_ROOT:=/etc/caddy}"
: "${CONVERGE_HOSTS_FILE:=/etc/hosts}"
: "${CONVERGE_MODULES_FILE:=/etc/modules-load.d/99-xboard-bbr.conf}"
: "${CONVERGE_SYSCTL_FILE:=/etc/sysctl.d/99-xboard-bbr.conf}"
: "${CONVERGE_HOST_TEST_MODE:=false}"
: "${CONVERGE_HOST_SKIP_LOCK:=false}"

[[ "$EXPECTED_WORKFLOW_SHA" =~ ^[0-9a-f]{40}$ ]] || converge_host_fail invalid_expected_workflow_sha
[[ "$SUBSCRIPTION_GATEWAY_TRUSTED_CIDR" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}/32$ ]] ||
  converge_host_fail invalid_trusted_cidr

IFS='/' read -r cidr_ip _ <<<"$SUBSCRIPTION_GATEWAY_TRUSTED_CIDR"
IFS='.' read -r oct1 oct2 oct3 oct4 <<<"$cidr_ip"
for octet in "$oct1" "$oct2" "$oct3" "$oct4"; do
  ((10#$octet >= 0 && 10#$octet <= 255)) || converge_host_fail invalid_trusted_cidr_octet
done

if [[ "$CONVERGE_HOST_TEST_MODE" != true ]]; then
  ((EUID == 0)) || converge_host_fail root_required
fi

for tool in awk caddy cmp hostname jq mktemp modprobe realpath sha256sum systemctl sysctl; do
  command -v "$tool" >/dev/null 2>&1 || converge_host_fail "tool_missing:$tool"
done

if [[ "$CONVERGE_HOST_TEST_MODE" != true || "$CONVERGE_HOST_SKIP_LOCK" != true ]]; then
  v2_find_workdir
  v2_acquire_lock
  release_assert_cleanup_admission "$V2_WORKDIR"
fi

hosts_candidate=''
modules_candidate=''
sysctl_candidate=''
caddy_desired=''
caddy_candidate=''
caddy_original=''

cleanup_converge_host() {
  rm -f -- \
    "$hosts_candidate" \
    "$modules_candidate" \
    "$sysctl_candidate" \
    "$caddy_desired" \
    "$caddy_candidate" \
    "$caddy_original"
}
trap cleanup_converge_host EXIT

hosts_resolves_locally() {
  local file=$1 target=$2
  awk -v h="$target" '
    /^[[:space:]]*#/ || NF < 2 { next }
    {
      sub(/#.*/, "")
      for (i = 2; i <= NF; i++) {
        if ($i == h) {
          found = 1
          exit 0
        }
      }
    }
    END { exit(found ? 0 : 1) }
  ' "$file"
}

host_name=${CONVERGE_HOSTNAME_OVERRIDE:-$(hostname)}
[[ "$host_name" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || converge_host_fail invalid_hostname
[[ -f "$CONVERGE_HOSTS_FILE" && ! -L "$CONVERGE_HOSTS_FILE" ]] || converge_host_fail invalid_hosts_file

hosts_updated=false
if ! hosts_resolves_locally "$CONVERGE_HOSTS_FILE" "$host_name"; then
  hosts_candidate=$(mktemp "${CONVERGE_HOSTS_FILE}.hostnet-candidate.XXXXXX")
  cp -p -- "$CONVERGE_HOSTS_FILE" "$hosts_candidate"
  if [[ -s "$hosts_candidate" ]] && [[ "$(tail -c 1 "$hosts_candidate" | wc -l | tr -d '[:space:]')" == 0 ]]; then
    printf '\n' >>"$hosts_candidate"
  fi
  printf '127.0.1.1\t%s\n' "$host_name" >>"$hosts_candidate"
  hosts_resolves_locally "$hosts_candidate" "$host_name" || converge_host_fail hosts_candidate_invalid
  chmod 0644 "$hosts_candidate"
  mv -f -- "$hosts_candidate" "$CONVERGE_HOSTS_FILE"
  hosts_candidate=''
  hosts_updated=true
fi

modules_dir=$(dirname -- "$CONVERGE_MODULES_FILE")
sysctl_dir=$(dirname -- "$CONVERGE_SYSCTL_FILE")
mkdir -p -- "$modules_dir" "$sysctl_dir"
[[ ! -L "$CONVERGE_MODULES_FILE" ]] || converge_host_fail symlink_modules_file
[[ ! -L "$CONVERGE_SYSCTL_FILE" ]] || converge_host_fail symlink_sysctl_file

modules_candidate=$(mktemp "${CONVERGE_MODULES_FILE}.hostnet-candidate.XXXXXX")
cat >"$modules_candidate" <<'EOF'
tcp_bbr
sch_fq
EOF
if [[ ! -f "$CONVERGE_MODULES_FILE" ]] || ! cmp -s "$modules_candidate" "$CONVERGE_MODULES_FILE"; then
  chmod 0644 "$modules_candidate"
  mv -f -- "$modules_candidate" "$CONVERGE_MODULES_FILE"
  modules_candidate=''
fi

modprobe tcp_bbr || converge_host_fail modprobe_tcp_bbr_failed
modprobe sch_fq || converge_host_fail modprobe_sch_fq_failed

sysctl_candidate=$(mktemp "${CONVERGE_SYSCTL_FILE}.hostnet-candidate.XXXXXX")
cat >"$sysctl_candidate" <<'EOF'
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
EOF
if [[ ! -f "$CONVERGE_SYSCTL_FILE" ]] || ! cmp -s "$sysctl_candidate" "$CONVERGE_SYSCTL_FILE"; then
  chmod 0644 "$sysctl_candidate"
  mv -f -- "$sysctl_candidate" "$CONVERGE_SYSCTL_FILE"
  sysctl_candidate=''
fi

sysctl -p "$CONVERGE_SYSCTL_FILE" >/dev/null || converge_host_fail sysctl_apply_failed
[[ "$(sysctl -n net.core.default_qdisc | tr -d '[:space:]')" == fq ]] ||
  converge_host_fail qdisc_not_fq
[[ "$(sysctl -n net.ipv4.tcp_congestion_control | tr -d '[:space:]')" == bbr ]] ||
  converge_host_fail congestion_control_not_bbr

if ! xboard_find_caddy_upstream; then
  converge_host_fail "caddy_discovery_failed:${XBOARD_DISCOVERY_ERROR:-unknown}"
fi

[[ -f "$XBOARD_CADDY_FILE" && ! -L "$XBOARD_CADDY_FILE" ]] || converge_host_fail invalid_caddy_file
caddy_config=$(realpath -e -- "$XBOARD_CADDY_FILE")
active_port=$XBOARD_ACTIVE_PORT
[[ "$active_port" =~ ^[0-9]{4,5}$ ]] || converge_host_fail invalid_active_port

caddy validate --config "$caddy_config" --adapter caddyfile >/dev/null ||
  converge_host_fail existing_caddy_invalid
current_json=$(caddy adapt --config "$caddy_config" --adapter caddyfile 2>/dev/null) ||
  converge_host_fail existing_caddy_adapt_failed

mapfile -t origin_hosts < <(
  jq -r '
    [
      .apps.http.servers[]?
      | (.routes // [])
      | ..
      | objects
      | .host? // empty
      | .[]?
      | select(type == "string" and test("^[A-Za-z0-9.-]+$"))
    ]
    | unique[]
  ' <<<"$current_json"
)
((${#origin_hosts[@]} == 1)) || converge_host_fail "ambiguous_origin_host:${#origin_hosts[@]}"
origin_host=${origin_hosts[0]}

listen_signature=$(jq -c '[.apps.http.servers[]?.listen // []] | flatten | unique' <<<"$current_json")
case "$listen_signature" in
  '[":443"]')
    site_address=$origin_host
    ;;
  '[":80"]')
    site_address="http://$origin_host"
    ;;
  *)
    converge_host_fail "unsupported_caddy_listen:$listen_signature"
    ;;
esac

assert_converged_caddy_json() {
  local adapted_json=$1
  jq -e \
    --arg host "$origin_host" \
    --arg dial "127.0.0.1:$active_port" \
    --arg cidr "$SUBSCRIPTION_GATEWAY_TRUSTED_CIDR" \
    --argjson expected_listen "$listen_signature" '
      ([.apps.http.servers[]?.listen // []] | flatten | unique) == $expected_listen
      and (
        [
          .apps.http.servers[]?
          | (.routes // [])
          | ..
          | objects
          | .host? // empty
          | .[]?
          | select(type == "string" and test("^[A-Za-z0-9.-]+$"))
        ] | unique
      ) == [$host]
      and (
        [
          .apps.http.servers[]?
          | select(
              .trusted_proxies.source == "static"
              and .trusted_proxies.ranges == [$cidr]
              and (.trusted_proxies_strict == 1 or .trusted_proxies_strict == true)
            )
        ] | length
      ) == 1
      and (
        [
          .apps.http.servers[]?
          | ..
          | objects
          | select(.handler? == "encode" and (.encodings.zstd != null) and (.encodings.gzip != null))
        ] | length
      ) >= 1
      and (
        [
          .apps.http.servers[]?
          | ..
          | objects
          | select(.handler? == "reverse_proxy")
          | .upstreams[]?.dial
        ]
      ) == [$dial]
    ' <<<"$adapted_json" >/dev/null
}

caddy_desired=$(mktemp "${caddy_config}.hostnet-desired.XXXXXX")
cat >"$caddy_desired" <<EOF
# Managed by converge-xboard-host-network.sh
{
	servers {
		trusted_proxies static ${SUBSCRIPTION_GATEWAY_TRUSTED_CIDR}
		trusted_proxies_strict
	}
}

${site_address} {
	encode zstd gzip
	reverse_proxy 127.0.0.1:${active_port}
}
EOF

caddy_changed=false
if cmp -s "$caddy_desired" "$caddy_config"; then
  assert_converged_caddy_json "$current_json" || converge_host_fail existing_converged_caddy_contract_failed
else
  caddy validate --config "$caddy_desired" --adapter caddyfile >/dev/null ||
    converge_host_fail candidate_caddy_invalid
  candidate_json=$(caddy adapt --config "$caddy_desired" --adapter caddyfile 2>/dev/null) ||
    converge_host_fail candidate_caddy_adapt_failed
  assert_converged_caddy_json "$candidate_json" ||
    converge_host_fail candidate_caddy_contract_failed

  caddy_candidate=$(mktemp "${caddy_config}.hostnet-candidate.XXXXXX")
  caddy_original=$(mktemp "${caddy_config}.hostnet-original.XXXXXX")
  cp -p -- "$caddy_desired" "$caddy_candidate"
  cp -p -- "$caddy_config" "$caddy_original"
  chmod 0644 "$caddy_candidate"

  swap_status=0
  mv -f -- "$caddy_candidate" "$caddy_config"
  caddy_candidate=''

  if ! xboard_find_caddy_upstream ||
     [[ "$(realpath -e -- "$XBOARD_CADDY_FILE")" != "$caddy_config" ]] ||
     [[ "$XBOARD_ACTIVE_PORT" != "$active_port" ]]; then
    swap_status=1
  elif ! systemctl reload caddy || [[ "$(systemctl is-active caddy)" != active ]]; then
    swap_status=1
  fi

  if ((swap_status != 0)); then
    cp -p -- "$caddy_original" "$caddy_config"
    chmod 0644 "$caddy_config"
    caddy validate --config "$caddy_config" --adapter caddyfile >/dev/null 2>&1 || true
    systemctl reload caddy >/dev/null 2>&1 || true
    converge_host_fail caddy_reload_failed
  fi
  caddy_changed=true
fi

echo "CONVERGE_HOST_NETWORK=PASS host=$host_name hosts_updated=$hosts_updated bbr=fq+bbr caddy_changed=$caddy_changed origin=$site_address active_port=$active_port trusted_cidr=$SUBSCRIPTION_GATEWAY_TRUSTED_CIDR"
