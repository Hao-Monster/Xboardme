#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

run_cleanup_case() (
  set -Eeuo pipefail
  local scenario=$1
  declare -A state=()
  declare -A present=([old-id]=true [active-id]=true [anchor-id]=true)
  declare -A present_image=(
    [sha256:eligible]=true
    [sha256:legacy-alias]=true
    [sha256:young]=true
    [sha256:third-party]=true
    [sha256:unsafe-alias]=true
  )

  EXPECTED_RETENTION_RESOURCE_FINGERPRINT=$(printf 'a%.0s' {1..64})
  EXPECTED_ACTIVE_RELEASE_ID=333-1
  EXPECTED_ACTIVE_RELEASE_SHA=$(printf 'b%.0s' {1..40})
  EXPECTED_WORKFLOW_SHA=$(printf 'c%.0s' {1..40})
  RETENTION_IMAGE_MIN_AGE_SECONDS=604800
  RETENTION_REQUIRE_FINALIZED=true
  RETENTION_ACQUIRE_LOCK=true
  resource_fingerprint=$EXPECTED_RETENTION_RESOURCE_FINGERPRINT
  active_release_id=$EXPECTED_ACTIVE_RELEASE_ID
  active_revision=$EXPECTED_ACTIVE_RELEASE_SHA
  active_project=xboard-v2-333-1
  active_traffic_state=finalized
  active_rollback_supported=false
  direct_previous_project=''
  active_container=active-id
  active_image_id=sha256:active
  active_redis_volume=xboard_redis-data
  XBOARD_ACTIVE_UPSTREAM=127.0.0.1:7002
  XBOARD_ACTIVE_PORT=7002
  XBOARD_ACTIVE_WEB=active-web
  XBOARD_ANCHOR_CONTAINER=anchor-id
  XBOARD_CADDY_FILE=$CLEANUP_TEST_CADDY
  active_app_data_path=$CLEANUP_TEST_DATA
  active_app_data_id=$(stat -c '%d:%i' "$active_app_data_path")
  active_state_file=$CLEANUP_TEST_STATE
  inventory=$CLEANUP_TEST_INVENTORY
  declare -A protected_ids=([active-id]=active [anchor-id]=anchor)

  if [[ "$scenario" == mismatch ]]; then
    resource_fingerprint=$(printf 'd%.0s' {1..64})
  elif [[ "$scenario" == retry ]]; then
    resource_fingerprint=$(printf 'd%.0s' {1..64})
    state[retention_cleanup_source_fingerprint]=$EXPECTED_RETENTION_RESOURCE_FINGERPRINT
    state[retention_cleanup_status]=failed
  elif [[ "$scenario" == complete ]]; then
    state[retention_cleanup_source_fingerprint]=$EXPECTED_RETENTION_RESOURCE_FINGERPRINT
    state[retention_cleanup_status]=complete
  fi

  release_state_get_optional() {
    printf '%s\n' "${state[$2]:-}"
  }
  release_state_set() {
    state[$2]=$3
  }
  xboard_find_caddy_upstream() {
    XBOARD_ACTIVE_UPSTREAM=127.0.0.1:7002
  }
  xboard_resolve_active_runtime() {
    XBOARD_ACTIVE_WEB=active-web
  }
  cleanup_mock_container_json() {
    cat <<'JSON'
[{"Id":"old-id","Name":"/old-web","State":{"Status":"exited"},"Image":"sha256:old","Config":{"Labels":{"org.opencontainers.image.revision":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","com.docker.compose.project":"xboard-v2-111-1","com.docker.compose.service":"web"}},"NetworkSettings":{"Ports":{}},"Mounts":[]}]
JSON
  }
  cleanup_mock_image_json() {
    local id=$1
    case "$id" in
      sha256:eligible)
        printf '[{"Id":"sha256:eligible","Size":1234,"Created":"2020-01-01T00:00:00Z","Config":{"Labels":{"org.opencontainers.image.revision":"%s","org.opencontainers.image.source":"https://github.com/Hao-Monster/Xboardme"}},"RepoTags":["ghcr.io/hao-monster/xboardme@sha256:eligible"],"RepoDigests":["ghcr.io/hao-monster/xboardme@sha256:eligible"]}]\n' "$(printf 'e%.0s' {1..40})"
        ;;
      sha256:legacy-alias)
        printf '[{"Id":"sha256:legacy-alias","Size":4567,"Created":"2020-01-01T00:00:00Z","Config":{"Labels":{"org.opencontainers.image.revision":"%s","org.opencontainers.image.source":"https://github.com/FengHaoyun-MONSTER/Xboardme"}},"RepoTags":["ghcr.io/fenghaoyun-monster/xboardme@sha256:legacy-alias","xboard-rollback:old-release"],"RepoDigests":["ghcr.io/fenghaoyun-monster/xboardme@sha256:legacy-alias","xboard-rollback@sha256:legacy-alias"]}]\n' "$(printf 'a%.0s' {1..40})"
        ;;
      sha256:young)
        printf '[{"Id":"sha256:young","Size":2345,"Created":"%s","Config":{"Labels":{"org.opencontainers.image.revision":"%s","org.opencontainers.image.source":"https://github.com/Hao-Monster/Xboardme"}},"RepoTags":["ghcr.io/hao-monster/xboardme@sha256:young"],"RepoDigests":["ghcr.io/hao-monster/xboardme@sha256:young"]}]\n' "$(date -u -d '1 day ago' +%FT%TZ)" "$(printf 'f%.0s' {1..40})"
        ;;
      sha256:third-party)
        printf '[{"Id":"sha256:third-party","Size":3456,"Created":"2020-01-01T00:00:00Z","Config":{"Labels":{"org.opencontainers.image.revision":"%s","org.opencontainers.image.source":"https://github.com/linuxserver/docker-bookstack"}},"RepoTags":["bookstack:test"],"RepoDigests":["bookstack@sha256:third-party"]}]\n' "$(printf '1%.0s' {1..40})"
        ;;
      sha256:unsafe-alias)
        printf '[{"Id":"sha256:unsafe-alias","Size":5678,"Created":"2020-01-01T00:00:00Z","Config":{"Labels":{"org.opencontainers.image.revision":"%s","org.opencontainers.image.source":"https://github.com/Hao-Monster/Xboardme"}},"RepoTags":["unexpected-local:keep"],"RepoDigests":["ghcr.io/hao-monster/xboardme@sha256:unsafe-alias"]}]\n' "$(printf '2%.0s' {1..40})"
        ;;
      *) return 1 ;;
    esac
  }
  docker() {
    local command=${1:-}
    shift || true
    case "$command" in
      container)
        local operation=${1:-} id=${2:-}
        case "$operation" in
          inspect) [[ ${present[$id]:-false} == true ]] ;;
          rm)
            [[ ${present[$id]:-false} == true ]]
            present[$id]=false
            printf 'container-rm %s\n' "$id" >> "$CLEANUP_TEST_LOG"
            ;;
          *) return 1 ;;
        esac
        ;;
      inspect)
        if [[ ${1:-} == -f ]]; then
          local format=$2 id=$3
          case "$format:$id" in
            *'.Id'*:active-web) printf 'active-id\n' ;;
            *'.Id'*:anchor-id) printf 'anchor-id\n' ;;
            *'.Image'*:anchor-id) printf 'sha256:anchor\n' ;;
            *'.Image'*:active-id) printf 'sha256:active\n' ;;
            *'.State.Status'*:active-id) printf 'running\n' ;;
            *'.State.Health'*:active-id) printf 'healthy\n' ;;
            *) return 1 ;;
          esac
        elif [[ ${1:-} == old-id && ${present[old-id]:-false} == true ]]; then
          cleanup_mock_container_json
        else
          return 1
        fi
        ;;
      ps)
        if [[ "$*" == *'--filter label=com.docker.compose.project=xboard-v2-333-1'* ]]; then
          printf 'active-id\n'
        else
          printf 'active-id\nanchor-id\n'
        fi
        ;;
      image)
        local operation=${1:-}
        shift || true
        case "$operation" in
          ls)
            local image_id
            for image_id in sha256:eligible sha256:legacy-alias sha256:young sha256:third-party sha256:unsafe-alias; do
              [[ ${present_image[$image_id]:-false} != true ]] || printf '%s\n' "$image_id"
            done
            ;;
          inspect)
            if [[ ${1:-} == -f && ${3:-} == sha256:active ]]; then
              printf '%s\n' "$EXPECTED_ACTIVE_RELEASE_SHA"
            elif [[ ${1:-} != -f && ${present_image[${1:-}]:-false} == true ]]; then
              cleanup_mock_image_json "$1"
            else
              return 1
            fi
            ;;
          rm)
            case ${1:-} in
              ghcr.io/hao-monster/xboardme@sha256:eligible|sha256:eligible)
                present_image[sha256:eligible]=false
                printf 'image-rm %s\n' "$1" >> "$CLEANUP_TEST_LOG"
                ;;
              ghcr.io/fenghaoyun-monster/xboardme@sha256:legacy-alias|xboard-rollback:old-release|xboard-rollback@sha256:legacy-alias|sha256:legacy-alias)
                present_image[sha256:legacy-alias]=false
                printf 'image-rm %s\n' "$1" >> "$CLEANUP_TEST_LOG"
                ;;
              *) return 1 ;;
            esac
            ;;
          *) return 1 ;;
        esac
        ;;
      volume)
        [[ ${1:-} == inspect && ${2:-} == xboard_redis-data ]]
        ;;
      *)
        printf 'unexpected-docker %s %s\n' "$command" "$*" >> "$CLEANUP_TEST_LOG"
        return 1
        ;;
    esac
  }
  caddy() { :; }

  # shellcheck source=cleanup-xboard-retention.sh
  source "$script_dir/cleanup-xboard-retention.sh"

  [[ ${state[retention_cleanup_status]} == complete ]]
  [[ ${state[retention_cleanup_removed_containers]} == 1 ]]
  [[ ${state[retention_cleanup_removed_images]} == 2 ]]
  [[ ${present[old-id]} == false ]]
  [[ ${present_image[sha256:eligible]} == false ]]
  [[ ${present_image[sha256:legacy-alias]} == false ]]
  [[ ${present_image[sha256:young]} == true ]]
  [[ ${present_image[sha256:third-party]} == true ]]
  [[ ${present_image[sha256:unsafe-alias]} == true ]]
)

CLEANUP_TEST_ROOT=$(mktemp -d)
CLEANUP_TEST_DATA=$CLEANUP_TEST_ROOT/data
CLEANUP_TEST_STATE=$CLEANUP_TEST_ROOT/state.json
CLEANUP_TEST_CADDY=$CLEANUP_TEST_ROOT/Caddyfile
CLEANUP_TEST_INVENTORY=$CLEANUP_TEST_ROOT/inventory.txt
CLEANUP_TEST_LOG=$CLEANUP_TEST_ROOT/mutations.log
export CLEANUP_TEST_DATA CLEANUP_TEST_STATE CLEANUP_TEST_CADDY CLEANUP_TEST_INVENTORY CLEANUP_TEST_LOG
trap 'rm -rf -- "$CLEANUP_TEST_ROOT"' EXIT
mkdir "$CLEANUP_TEST_DATA"
: > "$CLEANUP_TEST_STATE"
: > "$CLEANUP_TEST_CADDY"
cat > "$CLEANUP_TEST_INVENTORY" <<'INVENTORY'
RETENTION_CONTAINER name=old-web id=old-id status=exited classification=retired_v2_candidate project=xboard-v2-111-1 service=web maintenance_release=none legacy_release_run=none legacy_release_role=none stage_run=none revision=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb image_id=sha256:old ports=none mounts=none
INVENTORY
: > "$CLEANUP_TEST_LOG"

mismatch_output=$CLEANUP_TEST_ROOT/mismatch.out
set +e
run_cleanup_case mismatch >"$mismatch_output" 2>&1
mismatch_status=$?
set -e
((mismatch_status != 0))
grep -q 'RETENTION_CLEANUP_FAIL=resource_fingerprint_mismatch' "$mismatch_output"
[[ ! -s "$CLEANUP_TEST_LOG" ]]

run_cleanup_case happy >"$CLEANUP_TEST_ROOT/happy.out"
grep -qx 'container-rm old-id' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm ghcr.io/hao-monster/xboardme@sha256:eligible' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm ghcr.io/fenghaoyun-monster/xboardme@sha256:legacy-alias' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm xboard-rollback@sha256:legacy-alias' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm xboard-rollback:old-release' "$CLEANUP_TEST_LOG"
grep -q 'RETENTION_CLEANUP=PASS id=333-1 removed_containers=1' "$CLEANUP_TEST_ROOT/happy.out"

: > "$CLEANUP_TEST_LOG"
run_cleanup_case retry >"$CLEANUP_TEST_ROOT/retry.out"
grep -qx 'container-rm old-id' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm ghcr.io/hao-monster/xboardme@sha256:eligible' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm ghcr.io/fenghaoyun-monster/xboardme@sha256:legacy-alias' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm xboard-rollback@sha256:legacy-alias' "$CLEANUP_TEST_LOG"
grep -qx 'image-rm xboard-rollback:old-release' "$CLEANUP_TEST_LOG"
grep -q 'RETENTION_CLEANUP=PASS id=333-1 removed_containers=1' "$CLEANUP_TEST_ROOT/retry.out"

: > "$CLEANUP_TEST_LOG"
run_cleanup_case complete >"$CLEANUP_TEST_ROOT/complete.out"
grep -q 'result=already_complete' "$CLEANUP_TEST_ROOT/complete.out"
[[ ! -s "$CLEANUP_TEST_LOG" ]]

echo 'RETENTION_CLEANUP_TEST=PASS'
