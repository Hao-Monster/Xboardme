#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
target_script="$script_dir/converge-xboard-subscription-gateway.sh"

tmp_root=$(mktemp -d)
trap 'rm -rf -- "$tmp_root"' EXIT

mock_bin="$tmp_root/bin"
caddy_root="$tmp_root/etc/caddy"
state_dir="$tmp_root/state"
mkdir -p -- "$mock_bin" "$caddy_root" "$state_dir"

sha="89abcdef0123456789abcdef0123456789abcdef"
printf '%s\n' "$sha" >"$state_dir/web_revision"
printf 'OK' >"$state_dir/health_body"
printf '403' >"$state_dir/root_status"
printf '403' >"$state_dir/probe_status"
printf '{"message":"token is error"}\n' >"$state_dir/probe_body"
printf '0\n' >"$state_dir/exec_count"

cat >"$caddy_root/Caddyfile" <<'EOF'
cloud.thinderbox.com {
	encode zstd gzip
	reverse_proxy 127.0.0.1:7002
}
EOF

cat >"$mock_bin/docker" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
case "\${1:-}" in
  ps)
    shift
    if [[ "\$*" == *com.docker.compose.service=web* ]]; then
      printf 'web_cid_1\n'
    elif [[ "\$*" == *com.docker.compose.service=redis* ]]; then
      printf 'redis_cid_1\n'
    else
      printf 'edge_cid_1\nweb_cid_1\nredis_cid_1\n'
    fi
    ;;
  inspect)
    shift
    [[ "\${1:-}" == "-f" ]]
    fmt=\${2:?fmt}
    cid=\${3:?cid}
    if [[ "\$fmt" == *NetworkSettings.Ports* ]]; then
      if [[ "\$cid" == "edge_cid_1" ]]; then
        printf '7002\n'
      fi
    elif [[ "\$fmt" == *com.docker.compose.service* ]]; then
      case "\$cid" in
        edge_cid_1) printf 'edge\n' ;;
        web_cid_1) printf 'web\n' ;;
        redis_cid_1) printf 'redis\n' ;;
      esac
    elif [[ "\$fmt" == *com.docker.compose.project* ]]; then
      printf 'xboard-v2-100-1\n'
    elif [[ "\$fmt" == *org.opencontainers.image.revision* ]]; then
      cat "$state_dir/web_revision"
    fi
    ;;
  exec)
    count=\$(cat "$state_dir/exec_count")
    printf '%s\n' "\$((count + 1))" >"$state_dir/exec_count"
    stdin_php=\$(cat)
    grep -Fq 'SingBox::sanitizeMainlandRuleSetConfig' <<<"\$stdin_php"
    grep -Fq 'AbstractProtocol::sanitizeClashFakeIpBootstrapDns' <<<"\$stdin_php"
    printf 'CONVERGE_SUB_DB=PASS subscribe_url_updated=true templates_updated=2\n'
    ;;
  *)
    exit 1
    ;;
esac
EOF

cat >"$mock_bin/curl" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
out_file=""
write_code=false
url=""
while ((\$# > 0)); do
  case "\$1" in
    --output)
      out_file=\$2
      shift 2
      ;;
    --write-out)
      write_code=true
      shift 2
      ;;
    --max-time|-H)
      shift 2
      ;;
    --silent|--show-error|--fail)
      shift
      ;;
    http*://*)
      url=\$1
      shift
      ;;
    *)
      shift
      ;;
  esac
done

case "\$url" in
  */__sub_gateway_healthz__)
    cat "$state_dir/health_body"
    ;;
  https://sub.thinderbox.uk/)
    [[ -z "\$out_file" || "\$out_file" == "/dev/null" ]] || : >"\$out_file"
    if [[ "\$write_code" == true ]]; then
      cat "$state_dir/root_status"
    fi
    ;;
  */s/__gateway_origin_probe__)
    if [[ -n "\$out_file" && "\$out_file" != "/dev/null" ]]; then
      cat "$state_dir/probe_body" >"\$out_file"
    fi
    if [[ "\$write_code" == true ]]; then
      cat "$state_dir/probe_status"
    fi
    ;;
  *)
    exit 1
    ;;
esac
EOF

chmod +x "$mock_bin/docker" "$mock_bin/curl"

pass_out=$(
  PATH="$mock_bin:$PATH" \
  EXPECTED_WORKFLOW_SHA="$sha" \
  XBOARD_CADDY_ROOT="$caddy_root" \
  SUBSCRIPTION_GATEWAY_URL="https://sub.thinderbox.uk" \
  SUBSCRIPTION_ORIGIN_URL="https://cloud.thinderbox.com" \
  CONVERGE_SUB_GATEWAY_TEST_MODE=true \
  CONVERGE_SUB_GATEWAY_SKIP_LOCK=true \
  bash "$target_script"
)

grep -Fxq 'CONVERGE_SUBSCRIPTION_GATEWAY=PASS gateway=https://sub.thinderbox.uk active_web=web_cid_1 subscribe_url_updated=true templates_updated=2' <<<"$pass_out"
[[ "$(cat "$state_dir/exec_count")" == "1" ]]

# Gateway origin proxy probe returning 502 must block DB mutation
printf '502' >"$state_dir/probe_status"
printf 'Bad Gateway\n' >"$state_dir/probe_body"

if bad_probe_out=$(
  PATH="$mock_bin:$PATH" \
  EXPECTED_WORKFLOW_SHA="$sha" \
  XBOARD_CADDY_ROOT="$caddy_root" \
  SUBSCRIPTION_GATEWAY_URL="https://sub.thinderbox.uk" \
  SUBSCRIPTION_ORIGIN_URL="https://cloud.thinderbox.com" \
  CONVERGE_SUB_GATEWAY_TEST_MODE=true \
  CONVERGE_SUB_GATEWAY_SKIP_LOCK=true \
  bash "$target_script" 2>&1
); then
  echo "Expected 502 gateway probe to fail closed" >&2
  exit 1
fi

grep -Fq 'CONVERGE_SUB_GATEWAY_FAIL=gateway_origin_proxy_probe_failed:502' <<<"$bad_probe_out"
[[ "$(cat "$state_dir/exec_count")" == "1" ]]
