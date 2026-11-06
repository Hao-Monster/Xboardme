#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
target_script="$script_dir/converge-xboard-host-network.sh"

tmp_root=$(mktemp -d)
trap 'rm -rf -- "$tmp_root"' EXIT

mock_bin="$tmp_root/bin"
caddy_root="$tmp_root/etc/caddy"
modules_dir="$tmp_root/etc/modules-load.d"
sysctl_dir="$tmp_root/etc/sysctl.d"
state_dir="$tmp_root/state"
mkdir -p -- "$mock_bin" "$caddy_root" "$modules_dir" "$sysctl_dir" "$state_dir"

printf 'cubic\n' >"$state_dir/cc"
printf 'fq_codel\n' >"$state_dir/qdisc"
printf 'active\n' >"$state_dir/caddy_service"
printf '0\n' >"$state_dir/reload_count"

cat >"$mock_bin/modprobe" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
echo "\$1" >>"$state_dir/modprobe.log"
EOF

cat >"$mock_bin/sysctl" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "\${1:-}" == "-p" ]]; then
  file=\${2:?missing file}
  grep -Eq '^net\.core\.default_qdisc = fq$' "\$file"
  grep -Eq '^net\.ipv4\.tcp_congestion_control = bbr$' "\$file"
  printf 'fq\n' >"$state_dir/qdisc"
  printf 'bbr\n' >"$state_dir/cc"
  exit 0
fi
if [[ "\${1:-}" == "-n" ]]; then
  case "\${2:-}" in
    net.core.default_qdisc) cat "$state_dir/qdisc" ;;
    net.ipv4.tcp_congestion_control) cat "$state_dir/cc" ;;
    *) exit 1 ;;
  esac
  exit 0
fi
exit 1
EOF

cat >"$mock_bin/systemctl" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
case "\${1:-}" in
  reload)
    [[ "\${2:-}" == "caddy" ]]
    count=\$(cat "$state_dir/reload_count")
    printf '%s\n' "\$((count + 1))" >"$state_dir/reload_count"
    if [[ -f "$state_dir/fail_reload" ]]; then
      exit 1
    fi
    ;;
  is-active)
    [[ "\${2:-}" == "caddy" ]]
    cat "$state_dir/caddy_service"
    ;;
  *)
    exit 1
    ;;
esac
EOF

cat >"$mock_bin/caddy" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
cmd=${1:?cmd}
shift
[[ "${1:-}" == "--config" ]]
cfg=${2:?cfg}
[[ -f "$cfg" ]]

has_trusted=false
has_strict=false
has_encode=false
trusted_cidr=""
site_line=""
dial=""

while IFS= read -r line; do
  trimmed=$(sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//' <<<"$line")
  [[ -z "$trimmed" || "$trimmed" == \#* ]] && continue
  if [[ "$trimmed" =~ ^trusted_proxies[[:space:]]+static[[:space:]]+([^[:space:]]+)$ ]]; then
    has_trusted=true
    trusted_cidr=${BASH_REMATCH[1]}
  elif [[ "$trimmed" == "trusted_proxies_strict" ]]; then
    has_strict=true
  elif [[ "$trimmed" == "encode zstd gzip" ]]; then
    has_encode=true
  elif [[ "$trimmed" =~ ^(https?://)?([A-Za-z0-9.-]+)[[:space:]]*\{$ ]]; then
    site_line=$trimmed
  elif [[ "$trimmed" =~ ^reverse_proxy[[:space:]]+(127\.0\.0\.1:[0-9]{4,5})$ ]]; then
    dial=${BASH_REMATCH[1]}
  fi
done <"$cfg"

[[ -n "$site_line" && -n "$dial" ]]
scheme_prefix=""
if [[ "$site_line" == http://* ]]; then
  scheme_prefix="http://"
fi
host=$(sed -E 's#^https?://##; s#[[:space:]]*\{$##' <<<"$site_line")
listen='[":443"]'
if [[ "$scheme_prefix" == "http://" ]]; then
  listen='[":80"]'
fi

if [[ "$cmd" == "validate" ]]; then
  exit 0
fi

if [[ "$cmd" == "adapt" ]]; then
  jq -n \
    --arg host "$host" \
    --arg dial "$dial" \
    --arg cidr "$trusted_cidr" \
    --argjson listen "$listen" \
    --argjson has_trusted "$has_trusted" \
    --argjson has_strict "$has_strict" \
    --argjson has_encode "$has_encode" '
    {
      apps: {
        http: {
          servers: {
            srv0: (
              {
                listen: $listen,
                routes: [
                  {
                    match: [{host: [$host]}],
                    handle: (
                      (if $has_encode then [{handler: "encode", encodings: {zstd: {}, gzip: {}}}] else [] end)
                      + [{handler: "reverse_proxy", upstreams: [{dial: $dial}]}]
                    )
                  }
                ]
              }
              + (if $has_trusted then {trusted_proxies: {source: "static", ranges: [$cidr]}} else {} end)
              + (if $has_strict then {trusted_proxies_strict: 1} else {} end)
            )
          }
        }
      }
    }
  '
  exit 0
fi
exit 1
EOF

chmod +x "$mock_bin/modprobe" "$mock_bin/sysctl" "$mock_bin/systemctl" "$mock_bin/caddy"

hosts_file="$tmp_root/etc/hosts"
cat >"$hosts_file" <<'EOF'
127.0.0.1	localhost
104.192.83.51	debian
EOF

cat >"$caddy_root/Caddyfile" <<'EOF'
cloud.thinderbox.com {
	reverse_proxy 127.0.0.1:7002
}
EOF

sha="0123456789abcdef0123456789abcdef01234567"

first_out=$(
  PATH="$mock_bin:$PATH" \
  EXPECTED_WORKFLOW_SHA="$sha" \
  XBOARD_CADDY_ROOT="$caddy_root" \
  CONVERGE_HOSTS_FILE="$hosts_file" \
  CONVERGE_MODULES_FILE="$modules_dir/99-xboard-bbr.conf" \
  CONVERGE_SYSCTL_FILE="$sysctl_dir/99-xboard-bbr.conf" \
  CONVERGE_HOSTNAME_OVERRIDE="V20260731082214-001" \
  CONVERGE_HOST_TEST_MODE=true \
  CONVERGE_HOST_SKIP_LOCK=true \
  bash "$target_script"
)

grep -Fxq 'CONVERGE_HOST_NETWORK=PASS host=V20260731082214-001 hosts_updated=true bbr=fq+bbr caddy_changed=true origin=cloud.thinderbox.com active_port=7002 trusted_cidr=103.133.179.159/32' <<<"$first_out"
grep -Eq '^127\.0\.1\.1[[:space:]]+V20260731082214-001$' "$hosts_file"
grep -Fxq 'trusted_proxies static 103.133.179.159/32' < <(sed -E 's/^[[:space:]]+//' "$caddy_root/Caddyfile")
grep -Fxq 'trusted_proxies_strict' < <(sed -E 's/^[[:space:]]+//' "$caddy_root/Caddyfile")
grep -Fxq 'encode zstd gzip' < <(sed -E 's/^[[:space:]]+//' "$caddy_root/Caddyfile")
grep -Fxq 'reverse_proxy 127.0.0.1:7002' < <(sed -E 's/^[[:space:]]+//' "$caddy_root/Caddyfile")
[[ "$(cat "$state_dir/reload_count")" == "1" ]]

# Idempotent second run must not rewrite hosts or reload Caddy unnecessarily
second_out=$(
  PATH="$mock_bin:$PATH" \
  EXPECTED_WORKFLOW_SHA="$sha" \
  XBOARD_CADDY_ROOT="$caddy_root" \
  CONVERGE_HOSTS_FILE="$hosts_file" \
  CONVERGE_MODULES_FILE="$modules_dir/99-xboard-bbr.conf" \
  CONVERGE_SYSCTL_FILE="$sysctl_dir/99-xboard-bbr.conf" \
  CONVERGE_HOSTNAME_OVERRIDE="V20260731082214-001" \
  CONVERGE_HOST_TEST_MODE=true \
  CONVERGE_HOST_SKIP_LOCK=true \
  bash "$target_script"
)

grep -Fxq 'CONVERGE_HOST_NETWORK=PASS host=V20260731082214-001 hosts_updated=false bbr=fq+bbr caddy_changed=false origin=cloud.thinderbox.com active_port=7002 trusted_cidr=103.133.179.159/32' <<<"$second_out"
[[ "$(grep -c 'V20260731082214-001' "$hosts_file")" == "1" ]]
[[ "$(cat "$state_dir/reload_count")" == "1" ]]

# Failed Caddy reload must restore original Caddyfile atomically and fail closed
cat >"$caddy_root/Caddyfile" <<'EOF'
cloud.thinderbox.com {
	reverse_proxy 127.0.0.1:7003
}
EOF
before_fail_sha=$(sha256sum "$caddy_root/Caddyfile" | awk '{print $1}')
touch "$state_dir/fail_reload"

if fail_out=$(
  PATH="$mock_bin:$PATH" \
  EXPECTED_WORKFLOW_SHA="$sha" \
  XBOARD_CADDY_ROOT="$caddy_root" \
  CONVERGE_HOSTS_FILE="$hosts_file" \
  CONVERGE_MODULES_FILE="$modules_dir/99-xboard-bbr.conf" \
  CONVERGE_SYSCTL_FILE="$sysctl_dir/99-xboard-bbr.conf" \
  CONVERGE_HOSTNAME_OVERRIDE="V20260731082214-001" \
  CONVERGE_HOST_TEST_MODE=true \
  CONVERGE_HOST_SKIP_LOCK=true \
  bash "$target_script" 2>&1
); then
  echo "Expected failed reload to return non-zero" >&2
  exit 1
fi

grep -Fq 'CONVERGE_HOST_FAIL=caddy_reload_failed' <<<"$fail_out"
[[ "$(sha256sum "$caddy_root/Caddyfile" | awk '{print $1}')" == "$before_fail_sha" ]]
