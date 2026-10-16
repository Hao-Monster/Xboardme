#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/release-state.sh"

run_retain_online_case() (
  set -Eeuo pipefail
  local mode=$1
  local scenario=${2:-happy}

  local test_root
  test_root=$(mktemp -d)
  trap 'rm -rf -- "$test_root"' EXIT

  local workdir="$test_root/workdir"
  local lock_root="$workdir/.codex-v2-release"
  local active_release_id="37503793113-1"
  local old_release_id="36879702134-1"
  local active_project="xboard-v2-$active_release_id"
  local old_project="xboard-v2-$old_release_id"
  local active_dir="$lock_root/$active_release_id"
  local old_dir="$lock_root/$old_release_id"
  local legacy_dir="$workdir/.codex-release/31886878868-1"
  local app_data="$workdir/.docker/.data"
  local caddy_file="$test_root/Caddyfile"
  local state_file="$active_dir/state.json"

  mkdir -p "$active_dir" "$old_dir" "$legacy_dir" "$app_data"
  chmod 700 "$lock_root" "$active_dir"
  printf 'reverse_proxy 127.0.0.1:7002\n' > "$caddy_file"
  printf '{"status":"HOLD"}\n' > "$lock_root/image-cleanup-gate.json"
  printf '{"plan":1}\n' > "$lock_root/image-cleanup-plan-deadbeef.json"

  local active_sha
  active_sha=$(printf '1%.0s' {1..40})
  local workflow_sha
  workflow_sha=$(printf '2%.0s' {1..40})

  local anchor_id
  anchor_id=$(printf 'a%.0s' {1..64})
  local redis_id
  redis_id=$(printf 'b%.0s' {1..64})
  local web_id
  web_id=$(printf 'c%.0s' {1..64})
  local ws_id
  ws_id=$(printf 'd%.0s' {1..64})
  local edge_id
  edge_id=$(printf 'e%.0s' {1..64})
  local horizon_id
  horizon_id=$(printf 'f%.0s' {1..64})
  local scheduler_id
  scheduler_id=$(printf '0%.0s' {1..63})1
  local maint_id
  maint_id=$(printf '0%.0s' {1..63})2
  local old_cid
  old_cid=$(printf '9%.0s' {1..64})
  local old_maint_id
  old_maint_id=$(printf '7%.0s' {1..64})

  local img_app="sha256:$(printf '1%.0s' {1..64})"
  local img_caddy="sha256:$(printf '2%.0s' {1..64})"
  local img_redis="sha256:$(printf '3%.0s' {1..64})"
  local img_anchor="sha256:$(printf '4%.0s' {1..64})"
  local img_old="sha256:$(printf '8%.0s' {1..64})"

  local net_active_id="net_active_edge_id"
  local net_old_id="net_old_edge_id"

  if [[ "$scenario" == already-finalized ]]; then
    release_state_create "$state_file" \
      v2_schema_version 1 \
      release_id "$active_release_id" \
      release_sha "$active_sha" \
      project_name "$active_project" \
      redis_volume_name xboard_redis-data \
      app_data_path "$app_data" \
      maintenance_container "xboard-v2-maintenance-$active_release_id" \
      legacy_anchor_id "$old_cid" \
      traffic_state finalized \
      finalized_at "2026-10-16T03:37:46Z" \
      rollback_supported false \
      finalize_status complete
  else
    release_state_create "$state_file" \
      v2_schema_version 1 \
      release_id "$active_release_id" \
      release_sha "$active_sha" \
      project_name "$active_project" \
      redis_volume_name xboard_redis-data \
      app_data_path "$app_data" \
      maintenance_container "xboard-v2-maintenance-$active_release_id" \
      legacy_anchor_id "$old_cid" \
      traffic_state active_v2 \
      rollback_supported true
  fi

  local state_dir="$test_root/mock-docker"
  mkdir -p "$state_dir/containers" "$state_dir/images" "$state_dir/networks" "$state_dir/running"
  for cid in "$anchor_id" "$redis_id" "$web_id" "$ws_id" "$edge_id" "$horizon_id" "$scheduler_id" "$maint_id" "$old_cid" "$old_maint_id"; do
    : > "$state_dir/containers/$cid"
  done
  for cid in "$redis_id" "$web_id" "$ws_id" "$edge_id" "$horizon_id" "$scheduler_id" "$maint_id" "$old_maint_id"; do
    : > "$state_dir/running/$cid"
  done
  if [[ "$scenario" == old-running ]]; then
    : > "$state_dir/running/$old_cid"
  fi
  for iid in "$img_app" "$img_caddy" "$img_redis" "$img_anchor" "$img_old"; do
    : > "$state_dir/images/${iid#sha256:}"
  done
  : > "$state_dir/images/tag_old"
  : > "$state_dir/networks/$net_active_id"
  : > "$state_dir/networks/$net_old_id"

  EXPECTED_WORKFLOW_SHA=$workflow_sha
  CLEANUP_MODE=$mode
  CLEANUP_TEST_MODE=true

  retain_online_flock() { return 0; }
  retain_online_timeout() {
    shift
    "$@"
  }
  caddy() { return 0; }
  curl() { return 0; }
  ss() { return 0; }
  df() {
    if [[ -e "$state_dir/images/${img_old#sha256:}" ]]; then
      printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/vda2 51837932 46683720 5154212 91%% /\n'
    else
      printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/vda2 51837932 13183720 38654212 26%% /\n'
    fi
  }
  xboard_find_compose_anchor() {
    XBOARD_ANCHOR_CONTAINER=$anchor_id
    XBOARD_ANCHOR_WORKDIR=$workdir
  }
  xboard_find_caddy_upstream() {
    XBOARD_CADDY_FILE=$caddy_file
    XBOARD_ACTIVE_UPSTREAM="127.0.0.1:7002"
    XBOARD_ACTIVE_PORT=7002
  }
  xboard_resolve_active_runtime() {
    XBOARD_ACTIVE_TOPOLOGY=v2
    XBOARD_ACTIVE_WEB=$web_id
  }

  docker() {
    local sub=${1:-}
    shift || true
    case "$sub" in
      ps)
        if [[ "$*" == *"com.docker.compose.service=redis"* ]]; then
          printf '%s\n' "$redis_id"
        elif [[ "$*" == *"com.docker.compose.service=web"* ]]; then
          printf '%s\n' "$web_id"
        elif [[ "$*" == *"com.docker.compose.service=ws"* ]]; then
          printf '%s\n' "$ws_id"
        elif [[ "$*" == *"com.docker.compose.service=edge"* ]]; then
          printf '%s\n' "$edge_id"
        elif [[ "$*" == *"com.docker.compose.service=horizon"* ]]; then
          printf '%s\n' "$horizon_id"
        elif [[ "$*" == *"com.docker.compose.service=scheduler"* ]]; then
          printf '%s\n' "$scheduler_id"
        else
          for f in "$state_dir/containers/"*; do
            [[ -e "$f" ]] || continue
            basename -- "$f"
          done
        fi
        ;;
      stop)
        local target=${*: -1}
        [[ -e "$state_dir/containers/$target" ]] || return 1
        rm -f -- "$state_dir/running/$target"
        ;;
      volume)
        [[ ${1:-} == inspect && ${2:-} == xboard_redis-data ]]
        ;;
      container)
        local op=${1:-} target=${2:-}
        if [[ "$op" == inspect ]]; then
          [[ "$target" == "xboard-v2-maintenance-$active_release_id" ]] && target=$maint_id
          [[ -e "$state_dir/containers/$target" ]]
        elif [[ "$op" == stop ]]; then
          target=${*: -1}
          [[ -e "$state_dir/containers/$target" ]] || return 1
          rm -f -- "$state_dir/running/$target"
        elif [[ "$op" == rm ]]; then
          [[ -e "$state_dir/containers/$target" ]] || return 1
          rm -f -- "$state_dir/containers/$target" "$state_dir/running/$target"
        else
          return 1
        fi
        ;;
      network)
        local op=${1:-}
        shift || true
        case "$op" in
          ls)
            if [[ "$*" == *"label=com.docker.compose.project=$active_project"* ]]; then
              printf '%s\n' "$net_active_id"
            else
              [[ -e "$state_dir/networks/$net_active_id" ]] && printf '%s\t%s_edge\n' "$net_active_id" "$active_project"
              [[ -e "$state_dir/networks/$net_old_id" ]] && printf '%s\t%s_edge\n' "$net_old_id" "$old_project"
              printf 'net_bridge_id\tbridge\n'
            fi
            ;;
          inspect)
            local nid=${1:-}
            [[ -e "$state_dir/networks/$nid" ]] || return 1
            if [[ "$scenario" == network-busy && "$nid" == "$net_old_id" ]]; then
              printf '[{"Containers":{"c1":{}}}]\n'
            else
              printf '[{"Containers":{}}]\n'
            fi
            ;;
          rm)
            local nid=${1:-}
            [[ -e "$state_dir/networks/$nid" ]] || return 1
            rm -f -- "$state_dir/networks/$nid"
            ;;
          *) return 1 ;;
        esac
        ;;
      image)
        local op=${1:-}
        shift || true
        case "$op" in
          ls)
            for f in "$state_dir/images/"*; do
              [[ -e "$f" ]] || continue
              local base
              base=$(basename -- "$f")
              [[ "$base" == tag_* ]] && continue
              printf 'sha256:%s\n' "$base"
            done
            ;;
          inspect)
            if [[ ${1:-} == -f ]]; then
              local fmt=$2 target=$3
              if [[ "$fmt" == *'org.opencontainers.image.revision'* ]]; then
                printf '%s\n' "$active_sha"
              elif [[ "$fmt" == '{{.Id}}' ]]; then
                if [[ "$target" == "ghcr.io/hao-monster/xboardme:old" && -e "$state_dir/images/tag_old" ]]; then
                  printf '%s\n' "$img_old"
                elif [[ -e "$state_dir/images/${target#sha256:}" ]]; then
                  printf '%s\n' "$target"
                else
                  return 1
                fi
              else
                return 1
              fi
            else
              local target=${1:-}
              [[ -e "$state_dir/images/${target#sha256:}" ]] || return 1
              if [[ "$target" == "$img_old" && -e "$state_dir/images/tag_old" ]]; then
                printf '[{"RepoTags":["ghcr.io/hao-monster/xboardme:old"],"RepoDigests":[]}]\n'
              else
                printf '[{"RepoTags":[],"RepoDigests":[]}]\n'
              fi
            fi
            ;;
          rm)
            local target=${1:-}
            if [[ "$target" == "ghcr.io/hao-monster/xboardme:old" ]]; then
              rm -f -- "$state_dir/images/tag_old"
            elif [[ -e "$state_dir/images/${target#sha256:}" ]]; then
              rm -f -- "$state_dir/images/${target#sha256:}"
            else
              return 1
            fi
            ;;
          *) return 1 ;;
        esac
        ;;
      inspect)
        if [[ ${1:-} == -f ]]; then
          local fmt=$2 target=$3
          [[ "$target" == "xboard-v2-maintenance-$active_release_id" ]] && target=$maint_id
          [[ -e "$state_dir/containers/$target" ]] || return 1
          case "$fmt" in
            '{{.Id}}') printf '%s\n' "$target" ;;
            '{{ index .Config.Labels "com.docker.compose.service" }}')
              [[ "$target" == "$anchor_id" ]] && printf 'xboard\n' || printf 'web\n'
              ;;
            '{{ index .Config.Labels "com.docker.compose.project" }}')
              if [[ "$target" == "$old_cid" ]]; then
                printf '%s\n' "$old_project"
              elif [[ "$target" == "$old_maint_id" ]]; then
                printf '<no value>\n'
              else
                printf '%s\n' "$active_project"
              fi
              ;;
            '{{ index .Config.Labels "codex.xboard.v2.maintenance" }}')
              if [[ "$target" == "$maint_id" || "$target" == "$old_maint_id" ]]; then
                printf 'true\n'
              else
                printf '<no value>\n'
              fi
              ;;
            '{{ index .Config.Labels "codex.xboard.v2.release" }}')
              if [[ "$target" == "$maint_id" ]]; then
                printf '%s\n' "$active_release_id"
              elif [[ "$target" == "$old_maint_id" ]]; then
                printf '%s\n' "$old_release_id"
              else
                printf '<no value>\n'
              fi
              ;;
            '{{range $bindings := .NetworkSettings.Ports}}{{range $bindings}}{{println .HostPort}}{{end}}{{end}}')
              if [[ "$target" == "$edge_id" ]]; then
                printf '7002\n'
              elif [[ "$target" == "$maint_id" ]]; then
                printf '7004\n'
              elif [[ "$target" == "$old_maint_id" ]]; then
                printf '7003\n'
              fi
              ;;
            '{{.Image}}')
              case "$target" in
                "$anchor_id") printf '%s\n' "$img_anchor" ;;
                "$redis_id") printf '%s\n' "$img_redis" ;;
                "$edge_id"|"$maint_id"|"$old_maint_id") printf '%s\n' "$img_caddy" ;;
                "$old_cid") printf '%s\n' "$img_old" ;;
                *) printf '%s\n' "$img_app" ;;
              esac
              ;;
            '{{.State.Running}}')
              if [[ -e "$state_dir/running/$target" ]]; then
                printf 'true\n'
              else
                printf 'false\n'
              fi
              ;;
            '{{.State.Status}}')
              if [[ -e "$state_dir/running/$target" ]]; then
                printf 'running\n'
              else
                printf 'exited\n'
              fi
              ;;
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}')
              printf 'healthy\n'
              ;;
            '{{.RestartCount}}') printf '0\n' ;;
            '{{.State.OOMKilled}}') printf 'false\n' ;;
            *) return 1 ;;
          esac
        else
          local target=${1:-}
          [[ -e "$state_dir/containers/$target" ]] || return 1
          printf '[{"NetworkSettings":{"Networks":{"%s_edge":{"NetworkID":"%s"}}}}]\n' "$active_project" "$net_active_id"
        fi
        ;;
      *) return 1 ;;
    esac
  }

  # shellcheck source=cleanup-xboard-retain-online-only.sh
  source "$script_dir/cleanup-xboard-retain-online-only.sh"

  if [[ "$mode" == cleanup ]]; then
    [[ ! -e "$state_dir/containers/$old_cid" ]]
    [[ ! -e "$state_dir/containers/$old_maint_id" ]]
    [[ ! -e "$state_dir/networks/$net_old_id" ]]
    [[ ! -e "$state_dir/images/${img_old#sha256:}" ]]
    [[ ! -d "$old_dir" && ! -d "$legacy_dir" ]]
    [[ -d "$active_dir" && -d "$app_data" ]]
    [[ ! -e "$lock_root/image-cleanup-gate.json" ]]
    [[ ! -e "$lock_root/image-cleanup-plan-deadbeef.json" ]]
    [[ "$(release_state_get "$state_file" traffic_state)" == finalized ]]
    [[ "$(release_state_get "$state_file" rollback_supported)" == false ]]
    if [[ "$scenario" == already-finalized ]]; then
      [[ "$(release_state_get "$state_file" finalized_at)" == "2026-10-16T03:37:46Z" ]]
    else
      [[ "$(release_state_get "$state_file" previous_release_retired_id)" == "$old_release_id" ]]
      [[ "$(release_state_get "$state_file" previous_maintenance_retired)" == "$old_maint_id" ]]
    fi
  fi
)

test_out=$(mktemp -d)
trap 'rm -rf -- "$test_out"' EXIT

run_retain_online_case audit happy > "$test_out/audit.out"
grep -Fxq 'RETAIN_ONLINE_PROTECTED_CONTAINERS=8' "$test_out/audit.out"
grep -Fxq 'RETAIN_ONLINE_PROTECTED_IMAGES=4' "$test_out/audit.out"
grep -Fxq 'RETAIN_ONLINE_CANDIDATE_CONTAINERS=2' "$test_out/audit.out"
grep -Fxq 'RETAIN_ONLINE_CANDIDATE_NETWORKS=1' "$test_out/audit.out"
grep -Fxq 'RETAIN_ONLINE_CANDIDATE_DIRECTORIES=2' "$test_out/audit.out"
grep -Fxq 'RETAIN_ONLINE_CANDIDATE_IMAGES=1' "$test_out/audit.out"
grep -q 'RETAIN_ONLINE_CLEANUP=PASS mode=audit' "$test_out/audit.out"

set +e
run_retain_online_case cleanup old-running > "$test_out/old-running.out" 2>&1
old_running_status=$?
set -e
((old_running_status != 0))
grep -q 'RETAIN_ONLINE_CLEANUP_FAIL=non_protected_container_not_exited' "$test_out/old-running.out"

set +e
run_retain_online_case cleanup network-busy > "$test_out/network-busy.out" 2>&1
network_busy_status=$?
set -e
((network_busy_status != 0))
grep -q 'RETAIN_ONLINE_CLEANUP_FAIL=candidate_network_has_endpoints' "$test_out/network-busy.out"

run_retain_online_case cleanup happy > "$test_out/cleanup.out"
grep -Fxq 'RETAIN_ONLINE_REMOVED_CONTAINERS=2' "$test_out/cleanup.out"
grep -Fxq 'RETAIN_ONLINE_REMOVED_NETWORKS=1' "$test_out/cleanup.out"
grep -Fxq 'RETAIN_ONLINE_REMOVED_DIRECTORIES=2' "$test_out/cleanup.out"
grep -Fxq 'RETAIN_ONLINE_REMOVED_IMAGES=1' "$test_out/cleanup.out"
grep -Fxq 'RETAIN_ONLINE_REMAINING_CONTAINERS=8' "$test_out/cleanup.out"
grep -Fxq 'RETAIN_ONLINE_REMAINING_IMAGES=4' "$test_out/cleanup.out"
grep -Fxq 'RETAIN_ONLINE_RECLAIMED_KIB=33500000' "$test_out/cleanup.out"
grep -q 'RETAIN_ONLINE_CLEANUP=PASS mode=cleanup' "$test_out/cleanup.out"

run_retain_online_case cleanup already-finalized > "$test_out/already-finalized.out"
grep -q 'RETAIN_ONLINE_CLEANUP=PASS mode=cleanup' "$test_out/already-finalized.out"

echo 'RETAIN_ONLINE_CLEANUP_TEST=PASS'
