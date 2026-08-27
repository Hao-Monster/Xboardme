#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
runtime_discovery="$script_dir/production-runtime-discovery.sh"

test -f "$runtime_discovery"

temporary_dir=$(mktemp -d)
cleanup() {
  rm -rf -- "$temporary_dir"
}
trap cleanup EXIT

cat > "$temporary_dir/docker" <<'FAKE_DOCKER'
#!/usr/bin/env bash
set -Eeuo pipefail

edge=111111111111
web=222222222222
redis=333333333333
horizon=444444444444
scheduler=555555555555
anchor=aaaaaaaaaaaa
project=xboard-v2-32635082968-1
topology=${FAKE_TOPOLOGY:-v2}

case "${1:-}" in
  info)
    exit 0
    ;;
  ps)
    shift
    arguments=" $* "
    if [[ "$arguments" == *" -aq "* && "$arguments" == *" label=com.docker.compose.service=xboard "* ]]; then
      printf '%s\n' "$anchor"
    elif [[ "$topology" == v2 && "$arguments" == *" label=com.docker.compose.project=$project "* ]]; then
      case "$arguments" in
        *" label=com.docker.compose.service=web "*) printf '%s\n' "$web" ;;
        *" label=com.docker.compose.service=redis "*) printf '%s\n' "$redis" ;;
        *" label=com.docker.compose.service=horizon "*) printf '%s\n' "$horizon" ;;
        *" label=com.docker.compose.service=scheduler "*) printf '%s\n' "$scheduler" ;;
      esac
    elif [[ "$arguments" == *" -q "* ]]; then
      if [[ "$topology" == v2 ]]; then
        printf '%s\n' "$edge" "$web" "$redis" "$horizon" "$scheduler"
      else
        printf '%s\n' "$anchor"
      fi
    fi
    ;;
  inspect)
    template=$3
    container=$4
    case "$template" in
      *NetworkSettings.Ports*)
        if [[ "$topology" == v2 && "$container" == "$edge" ]]; then
          printf '7002\n'
        elif [[ "$topology" == legacy && "$container" == "$anchor" ]]; then
          printf '7001\n'
        fi
        ;;
      *com.docker.compose.project.working_dir*)
        [[ "$container" != "$anchor" ]] || printf '%s\n' "$FAKE_WORKDIR"
        ;;
      *com.docker.compose.project*)
        if [[ "$container" == "$anchor" ]]; then
          printf 'xboard\n'
        else
          printf '%s\n' "$project"
        fi
        ;;
      *com.docker.compose.service*)
        case "$container" in
          "$edge") printf 'edge\n' ;;
          "$web") printf 'web\n' ;;
          "$redis") printf 'redis\n' ;;
          "$horizon") printf 'horizon\n' ;;
          "$scheduler") printf 'scheduler\n' ;;
          "$anchor") printf 'xboard\n' ;;
        esac
        ;;
      *)
        echo "Unexpected inspect template: $template" >&2
        exit 1
        ;;
    esac
    ;;
  *)
    echo "Unexpected docker command: $*" >&2
    exit 1
    ;;
esac
FAKE_DOCKER
chmod +x "$temporary_dir/docker"
mkdir -p "$temporary_dir/workdir"
mkdir -p "$temporary_dir/caddy"
printf '%s\n' ':443 {' '  reverse_proxy 127.0.0.1:7002' '}' > "$temporary_dir/caddy/Caddyfile"
export FAKE_WORKDIR="$temporary_dir/workdir"
export PATH="$temporary_dir:$PATH"

# shellcheck disable=SC1090
source "$runtime_discovery"

XBOARD_CADDY_ROOT="$temporary_dir/caddy"
xboard_find_caddy_upstream
test "$XBOARD_CADDY_FILE" = "$temporary_dir/caddy/Caddyfile"
test "$XBOARD_ACTIVE_UPSTREAM" = 127.0.0.1:7002
test "$XBOARD_ACTIVE_PORT" = 7002

xboard_find_compose_anchor
test "$XBOARD_ANCHOR_CONTAINER" = aaaaaaaaaaaa
test "$XBOARD_ANCHOR_PROJECT" = xboard
test "$XBOARD_ANCHOR_WORKDIR" = "$temporary_dir/workdir"

xboard_resolve_active_runtime 7002
test "$XBOARD_ACTIVE_TOPOLOGY" = v2
test "$XBOARD_BOUND_CONTAINER" = 111111111111
test "$XBOARD_ACTIVE_WEB" = 222222222222
test "$XBOARD_ACTIVE_REDIS" = 333333333333
test "$XBOARD_ACTIVE_PROJECT" = xboard-v2-32635082968-1

mapfile -t horizon_ids < <(xboard_project_service_ids "$XBOARD_ACTIVE_PROJECT" horizon)
mapfile -t scheduler_ids < <(xboard_project_service_ids "$XBOARD_ACTIVE_PROJECT" scheduler)
test "${horizon_ids[*]}" = 444444444444
test "${scheduler_ids[*]}" = 555555555555

if xboard_resolve_active_runtime 7003; then
  echo 'An unbound production port unexpectedly resolved.' >&2
  exit 1
fi
test "$XBOARD_DISCOVERY_ERROR" = 'active_port_container_count=0'

FAKE_TOPOLOGY=legacy
export FAKE_TOPOLOGY
xboard_resolve_active_runtime 7001
test "$XBOARD_ACTIVE_TOPOLOGY" = legacy
test "$XBOARD_BOUND_CONTAINER" = aaaaaaaaaaaa
test "$XBOARD_ACTIVE_WEB" = aaaaaaaaaaaa
test -z "$XBOARD_ACTIVE_REDIS"

echo 'Production runtime discovery test passed.'
