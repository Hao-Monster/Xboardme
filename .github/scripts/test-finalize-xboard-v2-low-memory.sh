#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# Real state, owner, health and snapshot logic; only Docker and release-open
# boundaries are fake. No Docker daemon or production host is contacted.
source "$script_dir/release-state.sh"
source "$script_dir/v2-low-memory-common.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
case_count=0

full_id() { printf '%064x' "$1"; }
patch_container() {
  local id=$1 expression=$2
  jq "$expression" "$case_root/containers/$id.json" > "$case_root/patch.json"
  command mv "$case_root/patch.json" "$case_root/containers/$id.json"
}
make_container() {
  local number=$1 service=$2 project=$3 running=$4 port=${5:-} maintenance_release=${6:-}
  local id image revision config_image mounts='[]' name
  id=$(full_id "$number")
  name="$project-$service-1"
  image="sha256:$(printf 'c%.0s' {1..64})"
  revision=$EXPECTED_RELEASE_SHA
  config_image=$RELEASE_IMAGE
  if [[ "$project" != "$PROJECT_NAME" ]]; then
    revision=$legacy_sha
    image="sha256:$(printf 'd%.0s' {1..64})"
  fi
  if [[ "$service" == maintenance ]]; then
    image="sha256:$(printf 'e%.0s' {1..64})"
    config_image=caddy@sha256:$(printf 'f%.0s' {1..64})
    name="xboard-v2-maintenance-$maintenance_release"
    mounts=$(jq -cn --arg source "$V2_WORKDIR/.codex-v2-release/$maintenance_release/.docker/caddy/Caddyfile.maintenance" \
      '[{Type:"bind",Source:$source,Destination:"/etc/caddy/Caddyfile",RW:false,Mode:"ro",Propagation:"rprivate"}]')
  elif [[ "$service" == redis || "$service" == xboard ]]; then
    mounts=$(jq -cn --arg source "$case_root/volume" --arg name "$REDIS_VOLUME_NAME" \
      '[{Type:"volume",Source:$source,Name:$name,Destination:"/data",RW:true,Mode:"",Propagation:""}]')
  elif [[ "$service" != edge ]]; then
    mounts=$(jq -cn --arg source "$APP_DATA_PATH" \
      '[{Type:"bind",Source:$source,Destination:"/www/.docker/.data",RW:true,Mode:"rw",Propagation:"rprivate"}]')
  fi
  jq -n --arg id "$id" --arg name "/$name" --arg image "$image" --arg config_image "$config_image" \
    --arg role "$service" --arg project "$project" --arg revision "$revision" --arg release "$maintenance_release" \
    --argjson running "$running" --arg port "$port" --argjson mounts "$mounts" '
    {Id:$id,Name:$name,Image:$image,Config:{Image:$config_image,Labels:({
      "com.docker.compose.project":$project,"com.docker.compose.service":$role,
      "org.opencontainers.image.revision":$revision} +
      (if $release == "" then {} else {"codex.xboard.v2.release":$release,"codex.xboard.v2.maintenance":"true"} end))},
      State:{Status:(if $running then "running" else "exited" end),Running:$running,
        Paused:false,Restarting:false,Dead:false,OOMKilled:false,StartedAt:"2020-01-01T00:00:00Z",Health:{Status:"healthy"}},
      RestartCount:0,Mounts:$mounts,
      HostConfig:{PortBindings:(if $port == "" then {} else {"7001/tcp":[{HostIp:"127.0.0.1",HostPort:$port}]} end)},
      NetworkSettings:{Ports:{},Networks:{}}}' > "$case_root/containers/$id.json"
}
setup_case() {
  case_root="$test_root/$1"
  mkdir -p "$case_root/containers" "$case_root/data" "$case_root/volume"
  RELEASE_ID=333-1
  EXPECTED_RELEASE_SHA=$(printf 'a%.0s' {1..40})
  RELEASE_SHA=$EXPECTED_RELEASE_SHA
  legacy_sha=$(printf 'd%.0s' {1..40})
  RELEASE_IMAGE=ghcr.io/example/xboard@sha256:$(printf 'b%.0s' {1..64})
  PROJECT_NAME=xboard-v2-$RELEASE_ID
  V2_WORKDIR="$case_root/work"
  V2_RELEASE_DIR="$V2_WORKDIR/.codex-v2-release/$RELEASE_ID"
  V2_STATE_FILE="$V2_RELEASE_DIR/state.json"
  APP_DATA_PATH="$case_root/data"
  REDIS_VOLUME_NAME=xboard_redis-data
  ACTIVE_PORT=7002
  MAINTENANCE_PORT=7006
  CADDY_CONFIG="$case_root/Caddyfile"
  MAINTENANCE_CONTAINER=xboard-v2-maintenance-$RELEASE_ID
  LEGACY_TOPOLOGY=v2
  LEGACY_ANCHOR_ID=$(full_id 20)
  LEGACY_WEB_ID=$(full_id 21)
  LEGACY_WS_ID=$(full_id 22)
  LEGACY_EDGE_ID=$(full_id 23)
  LEGACY_HORIZON_ID=$(full_id 24)
  LEGACY_SCHEDULER_ID=$(full_id 25)
  V2_ANCHOR_DISCOVERY_ID=$(full_id 8)
  mkdir -p "$V2_RELEASE_DIR/.docker/caddy" "$V2_WORKDIR/.codex-v2-release/111-1/.docker/caddy"
  printf 'reverse_proxy 127.0.0.1:7002\n' > "$CADDY_CONFIG"
  printf 'respond /health 200\n' > "$V2_RELEASE_DIR/.docker/caddy/Caddyfile.maintenance"
  printf 'respond /health 200\n' > "$V2_WORKDIR/.codex-v2-release/111-1/.docker/caddy/Caddyfile.maintenance"
  release_state_create "$V2_STATE_FILE" traffic_state active_v2 rollback_supported true \
    switched_at 2020-01-01T00:00:00Z legacy_release_sha "$legacy_sha" \
    maintenance_image "caddy@sha256:$(printf 'f%.0s' {1..64})" \
    maintenance_container "$MAINTENANCE_CONTAINER" maintenance_port "$MAINTENANCE_PORT"
  release_state_create "$V2_WORKDIR/.codex-v2-release/111-1/state.json" release_id 111-1 \
    project_name xboard-v2-111-1 release_sha "$legacy_sha" traffic_state finalized rollback_supported false \
    maintenance_port 7004 maintenance_image "caddy@sha256:$(printf 'f%.0s' {1..64})"
  local index=1 role
  for role in redis web ws edge horizon scheduler; do
    make_container "$index" "$role" "$PROJECT_NAME" true "$([[ "$role" == edge ]] && echo 7002 || true)"
    make_container "$((index + 19))" "$role" xboard-v2-111-1 false "$([[ "$role" == edge ]] && echo 7003 || true)"
    ((index += 1))
  done
  make_container 7 maintenance "$PROJECT_NAME" true 7006 333-1
  make_container 8 xboard xboard false
  make_container 26 maintenance xboard-v2-111-1 true 7004 111-1
  : > "$case_root/removals.log"
  RETENTION_REVIEW_MANIFEST_B64=$(printf 'reviewed' | base64 -w0)
  EXPECTED_RETENTION_REVIEW_SHA256=$(printf 'reviewed' | sha256sum | awk '{print $1}')
  RETENTION_MAX_OBJECTS_PER_RUN=5
  unset V2_FINALIZE_REASON SUPERSEDING_RELEASE_SHA V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID FAIL_POINT DRIFT_AFTER_REMOVE REVIEW_DENIED
}

find_fixture() {
  local reference=$1 id
  if [[ -f "$case_root/containers/$reference.json" ]]; then
    printf '%s\n' "$case_root/containers/$reference.json"; return 0
  fi
  id=$(jq -ers --arg name "/$reference" '[.[]|select(.Name==$name)] |
    if length==1 then .[0].Id else error("missing or ambiguous fixture") end' "$case_root"/containers/*.json) || return 1
  printf '%s\n' "$case_root/containers/$id.json"
}
docker() {
  local command=${1:?} file format id role
  shift
  case "$command" in
    container)
      [[ "$1" == inspect ]]
      file=$(find_fixture "$2") || return 1
      jq -s . "$file"
      ;;
    inspect)
      [[ "$1" == -f ]] || { file=$(find_fixture "$1") && jq -s . "$file"; return; }
      format=$2
      file=$(find_fixture "$3") || return 1
      case "$format" in
        '{{.Id}}') jq -r .Id "$file" ;;
        '{{.Image}}') jq -r .Image "$file" ;;
        '{{.State.Running}}') jq -r .State.Running "$file" ;;
        '{{.State.OOMKilled}}') jq -r .State.OOMKilled "$file" ;;
        '{{.RestartCount}}') jq -r .RestartCount "$file" ;;
        *State.Health*) jq -r .State.Health.Status "$file" ;;
        *com.docker.compose.project*) jq -r '.Config.Labels["com.docker.compose.project"]' "$file" ;;
        *org.opencontainers.image.revision*) jq -r '.Config.Labels["org.opencontainers.image.revision"]' "$file" ;;
        *'/www/.docker/.data'*) jq -r '[.Mounts[] | select(.Destination=="/www/.docker/.data") | .Source] | join("")' "$file" ;;
        *'"/data"'*) jq -r '[.Mounts[] | select(.Destination=="/data") | .Name] | join("")' "$file" ;;
        *) echo "FINALIZE_TEST_FAIL=unexpected_inspect:$format" >&2; return 1 ;;
      esac
      ;;
    image)
      [[ "$1" == inspect && "$2" == -f ]]
      case "$3" in
        '{{.Id}}') printf 'sha256:%s\n' "$(printf 'e%.0s' {1..64})" ;;
        *org.opencontainers.image.revision*)
          if [[ "$4" == "sha256:$(printf 'd%.0s' {1..64})" ]]; then printf '%s\n' "$legacy_sha";
          else printf '%s\n' "$EXPECTED_RELEASE_SHA"; fi
          ;;
        *) return 1 ;;
      esac
      ;;
    volume)
      [[ "$1" == inspect && "$2" == "$REDIS_VOLUME_NAME" ]]
      jq -cn --arg name "$REDIS_VOLUME_NAME" --arg source "$case_root/volume" \
        '[{Name:$name,Driver:"local",Mountpoint:$source,CreatedAt:"2020-01-01T00:00:00Z",Scope:"local"}]'
      ;;
    ps)
      if [[ "$*" == *-aq* ]]; then
        jq -rs '.[].Id' "$case_root"/containers/*.json
      else
        jq -rs '.[]|select(.State.Running)|.Id' "$case_root"/containers/*.json
      fi
      ;;
    port)
      file=$(find_fixture "$1") || return 1
      jq -r '.HostConfig.PortBindings["7001/tcp"][]? | .HostIp+":"+.HostPort' "$file"
      ;;
    exec)
      file=$(find_fixture "$1") || return 1
      role=$(jq -r '.Config.Labels["com.docker.compose.service"]' "$file")
      [[ "$role" != horizon && "$role" != scheduler ]] || printf '%s\n' "$role"
      ;;
    rm)
      [[ $# == 1 && "$1" =~ ^[0-9a-f]{64}$ ]] || { echo 'FINALIZE_TEST_FAIL=unsafe_rm' >&2; return 1; }
      id=$1
      if [[ ${FAIL_POINT:-} == rm && ! -f "$case_root/injected" ]]; then touch "$case_root/injected"; return 72; fi
      file=$(find_fixture "$id") || return 1
      [[ "$(jq -r .State.Status "$file")" == exited ]]
      rm -- "$file"
      printf '%s\n' "$id" >> "$case_root/removals.log"
      if [[ ${FAIL_POINT:-} == term && ! -f "$case_root/injected" ]]; then
        touch "$case_root/injected"
        kill -TERM "$BASHPID"
      fi
      if [[ -n ${DRIFT_AFTER_REMOVE:-} && ! -f "$case_root/drifted" ]]; then
        touch "$case_root/drifted"
        case "$DRIFT_AFTER_REMOVE" in
          restart) patch_container "$(full_id 2)" '.RestartCount=1' ;;
          route) printf 'reverse_proxy 127.0.0.1:7999\n' > "$CADDY_CONFIG" ;;
          maintenance) patch_container "$(full_id 7)" '.Image="sha256:'"$(printf '9%.0s' {1..64})"'"' ;;
          mount) patch_container "$(full_id 2)" '.Mounts[0].RW=false' ;;
        esac
      fi
      ;;
    *) echo "FINALIZE_TEST_FAIL=unexpected_docker:$command $*" >&2; return 1 ;;
  esac
}
v2_require_tools() { :; }
timeout() {
  [[ "$1" == --signal=TERM && "$2" == --kill-after=5s && "$3" == 60s && "$4" == docker && "$5" == rm ]]
  shift 3
  "$@"
}
v2_open_release() { TRAFFIC_STATE=$(release_state_get "$V2_STATE_FILE" traffic_state); }
v2_compose() {
  [[ "$1" == ps && "$2" == --quiet ]]
  jq -rs --arg project "$PROJECT_NAME" --arg service "$3" \
    '.[]|select(.Config.Labels["com.docker.compose.project"]==$project and
      .Config.Labels["com.docker.compose.service"]==$service)|.Id' "$case_root"/containers/*.json
}
retention_review_validate_finalize() {
  [[ ${REVIEW_DENIED:-false} == false ]] || { v2_fail finalize_review_not_approved; return 1; }
  # External review schema/fingerprint logic has its own retention suite;
  # this gate double ensures the call happens before any destructive intent.
  [[ "$(release_state_get "$V2_STATE_FILE" traffic_state)" == active_v2 ]]
  [[ ! -s "$case_root/removals.log" ]]
  jq -e '.containers | length > 0' <<<"$1" >/dev/null
  printf 'reviewed\n' > "$case_root/reviewed"
}
mv() {
  local target=${@: -1} temporary=${@: -2:1}
  if [[ "$target" == "$V2_STATE_FILE" && -n ${FAIL_POINT:-} && ! -f "$case_root/injected" ]]; then
    if { [[ "$FAIL_POINT" == intent ]] && jq -e '.traffic_state=="finalizing"' "$temporary" >/dev/null; } ||
       { [[ "$FAIL_POINT" == terminal ]] && jq -e '.traffic_state=="finalized"' "$temporary" >/dev/null; } ||
       { [[ "$FAIL_POINT" == checkpoint ]] && jq -e '(.finalize_completed_ids // "[]" | fromjson | length)>0' "$temporary" >/dev/null; }; then
      touch "$case_root/injected"
      return 73
    fi
  fi
  command mv "$@"
}
run_attempt() (
  set -Eeuo pipefail
  source "$script_dir/finalize-xboard-v2-low-memory.sh"
)
expect_attempt() {
  local expected=$1 status attempt
  for attempt in {1..8}; do
    set +e
    run_attempt > "$case_root/output.log" 2>&1
    status=$?
    set -e
    if ((status != 0)) || [[ "$expected" == pending ]] || ! grep -Fxq 'V2_FINALIZE_STATUS=pending_batch' "$case_root/output.log"; then break; fi
  done
  if [[ "$expected" == pass ]]; then
    if ((status != 0)); then cat "$case_root/output.log" >&2; return 1; fi
    [[ "$(release_state_get "$V2_STATE_FILE" traffic_state)" == finalized ]]
    [[ "$(release_state_get "$V2_STATE_FILE" rollback_supported)" == false ]]
  elif [[ "$expected" == pending ]]; then
    ((status == 0))
    grep -Fxq 'V2_FINALIZE_STATUS=pending_batch' "$case_root/output.log"
    [[ "$(release_state_get "$V2_STATE_FILE" traffic_state)" == finalizing ]]
    [[ "$(release_state_get "$V2_STATE_FILE" rollback_supported)" == false ]]
  else
    if ((status == 0)); then echo "FINALIZE_TEST_FAIL=unexpected_success:$expected" >&2; return 1; fi
    [[ "$expected" == injected ]] || grep -Fq "$expected" "$case_root/output.log"
  fi
  [[ -f "$case_root/containers/$(full_id 7).json" && -f "$case_root/containers/$(full_id 8).json" ]]
  ! grep -Fxq "$(full_id 7)" "$case_root/removals.log"
  ! grep -Fxq "$(full_id 8)" "$case_root/removals.log"
}
finish_case() {
  ((case_count += 1))
  printf 'FINALIZE_CASE=PASS name=%s\n' "${case_root##*/}"
}

setup_case manual_one_object_batch
RETENTION_MAX_OBJECTS_PER_RUN=1
expect_attempt pending
[[ $(wc -l < "$case_root/removals.log") == 1 ]]
expect_attempt pending
[[ $(wc -l < "$case_root/removals.log") == 2 ]]
finish_case

setup_case preserve_current
expect_attempt pass
[[ $(wc -l < "$case_root/removals.log") == 6 ]]
[[ -f "$case_root/containers/$(full_id 26).json" ]]
[[ "$(release_state_get "$V2_STATE_FILE" current_maintenance_preserved)" == "$(full_id 7)" ]]
[[ "$(release_state_get "$V2_STATE_FILE" maintenance_container)" == "$MAINTENANCE_CONTAINER" ]]
expect_attempt invalid_finalize_state:finalized
finish_case

setup_case legacy
LEGACY_TOPOLOGY=legacy
LEGACY_ANCHOR_ID=$V2_ANCHOR_DISCOVERY_ID
LEGACY_WS_ID=''
LEGACY_EDGE_ID=''
expect_attempt pass
[[ $(wc -l < "$case_root/removals.log") == 3 ]]
finish_case

setup_case superseded
V2_FINALIZE_REASON=superseded
SUPERSEDING_RELEASE_SHA=$(printf 'b%.0s' {1..40})
release_state_set "$V2_STATE_FILE" switched_at "$(date -u +%FT%TZ)"
REVIEW_DENIED=true
expect_attempt pass
[[ ! -e "$case_root/reviewed" ]]
finish_case

for failure in too_early same_candidate review_denied missing_plan old_running maintenance_label maintenance_binding maintenance_mount maintenance_image route_owner unhealthy; do
  setup_case "$failure"
  case "$failure" in
    too_early) release_state_set "$V2_STATE_FILE" switched_at "$(date -u +%FT%TZ)"; expected=rollback_window_open ;;
    same_candidate) V2_FINALIZE_REASON=superseded; SUPERSEDING_RELEASE_SHA=$EXPECTED_RELEASE_SHA; expected=superseding_release_matches_active ;;
    review_denied) REVIEW_DENIED=true; expected=finalize_review_not_approved ;;
    missing_plan) release_state_set_many "$V2_STATE_FILE" traffic_state finalizing rollback_supported false; expected='missing or non-string release state key' ;;
    old_running) patch_container "$(full_id 21)" '.State.Running=true | .State.Status="running"'; expected=legacy_owner_still_running ;;
    maintenance_label) patch_container "$(full_id 7)" '.Config.Labels["codex.xboard.v2.release"]="999-1"'; expected=maintenance_identity_mismatch ;;
    maintenance_binding) patch_container "$(full_id 7)" '.HostConfig.PortBindings["7001/tcp"][0].HostIp="0.0.0.0"'; expected=maintenance_identity_mismatch ;;
    maintenance_mount) patch_container "$(full_id 7)" '.Mounts[0].RW=true'; expected=maintenance_identity_mismatch ;;
    maintenance_image) patch_container "$(full_id 7)" '.Image="sha256:'"$(printf '9%.0s' {1..64})"'"'; expected=maintenance_identity_mismatch ;;
    route_owner) patch_container "$(full_id 4)" '.HostConfig.PortBindings["7001/tcp"][0].HostPort="7999"'; expected=finalize_active_port_owner_mismatch ;;
    unhealthy) patch_container "$(full_id 2)" '.State.Health.Status="unhealthy"'; expected=finalize_service_unhealthy:web ;;
  esac
  expect_attempt "$expected"
  [[ ! -s "$case_root/removals.log" ]]
  finish_case
done

for point in intent rm checkpoint terminal term; do
  setup_case "failure_$point"
  FAIL_POINT=$point
  expect_attempt injected
  if [[ "$point" == intent ]]; then
    [[ "$(release_state_get "$V2_STATE_FILE" traffic_state)" == active_v2 && ! -s "$case_root/removals.log" ]]
    [[ "$(release_state_get "$V2_STATE_FILE" rollback_supported)" == true ]]
  else
    [[ "$(release_state_get "$V2_STATE_FILE" traffic_state)" == finalizing ]]
    [[ "$(release_state_get "$V2_STATE_FILE" rollback_supported)" == false ]]
  fi
  if [[ "$point" == rm ]]; then
    make_container 30 web xboard-v2-111-1 false
  fi
  unset FAIL_POINT
  expect_attempt pass
  [[ $(wc -l < "$case_root/removals.log") == 6 ]]
  [[ "$point" != rm || -f "$case_root/containers/$(full_id 30).json" ]]
  finish_case
done

for drift in restart route maintenance mount; do
  setup_case "drift_$drift"
  DRIFT_AFTER_REMOVE=$drift
  expect_attempt injected
  [[ $(wc -l < "$case_root/removals.log") == 1 ]]
  [[ "$(release_state_get "$V2_STATE_FILE" traffic_state)" == finalizing ]]
  unset DRIFT_AFTER_REMOVE
  expect_attempt injected
  [[ $(wc -l < "$case_root/removals.log") == 1 ]]
  finish_case
done

setup_case retry_changed_reason
FAIL_POINT=rm
expect_attempt injected
unset FAIL_POINT
V2_FINALIZE_REASON=superseded
SUPERSEDING_RELEASE_SHA=$(printf 'b%.0s' {1..40})
expect_attempt finalize_retry_identity_changed
[[ ! -s "$case_root/removals.log" ]]
finish_case

setup_case retry_changed_history
FAIL_POINT=rm
expect_attempt injected
unset FAIL_POINT
release_state_set "$V2_WORKDIR/.codex-v2-release/111-1/state.json" traffic_state rolled_back
expect_attempt finalize_previous_state_changed
[[ ! -s "$case_root/removals.log" ]]
finish_case

setup_case unexplained_missing
FAIL_POINT=rm
expect_attempt injected
unset FAIL_POINT
rm -- "$case_root/containers/$(full_id 21).json"
expect_attempt finalize_unexplained_missing_container
[[ $(wc -l < "$case_root/removals.log") == 1 ]]
finish_case

setup_case stopped_previous_maintenance
V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID=$(full_id 26)
patch_container "$V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID" '.State.Running=false | .State.Status="exited"'
expect_attempt pass
[[ $(wc -l < "$case_root/removals.log") == 7 ]]
[[ ! -e "$case_root/containers/$(full_id 26).json" ]]
finish_case

setup_case running_previous_maintenance
V2_FINALIZE_RETIRE_PREVIOUS_MAINTENANCE_ID=$(full_id 26)
expect_attempt previous_maintenance_not_exited
[[ ! -s "$case_root/removals.log" ]]
finish_case

setup_case unresolved_image_cleanup
printf '{"status":"HOLD"}\n' > "$V2_WORKDIR/.codex-v2-release/image-cleanup-gate.json"
state_before=$(sha256sum "$V2_STATE_FILE")
expect_attempt cleanup_admission_helper_missing
[[ ! -s "$case_root/removals.log" && ! -e "$case_root/reviewed" ]]
[[ "$(sha256sum "$V2_STATE_FILE")" == "$state_before" ]]
finish_case

printf 'FINALIZE_V2_LOW_MEMORY_TEST=PASS cases=%s\n' "$case_count"
