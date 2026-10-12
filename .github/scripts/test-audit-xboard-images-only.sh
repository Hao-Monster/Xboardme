#!/usr/bin/env bash
set -Eeuo pipefail

# Offline independent audit contract. Host discovery, Docker metadata,
# systemctl and startup parsing are explicit fixtures; no Docker command or
# production host access is possible. Real snapshot, protection, release-state,
# fingerprint and flock code is exercised. This is not live-runtime evidence.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/release-state.sh"
source "$script_dir/retention-safety.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
passed=0
full_id() { printf '%064x' "$1"; }
active_sha=$(printf 'a%.0s' {1..40})
workflow_sha=$(printf 'b%.0s' {1..40})
app_image="sha256:$(full_id 91)"
anchor_id=$(full_id 9)
maintenance_id=$(full_id 8)

raw_container() {
  jq -cn --arg id "$1" --arg name "$2" --arg project "$3" --arg service "$4" \
    --arg status "$5" --arg image "$app_image" --arg sha "$active_sha" --arg data "$case_root/data" '{
      Id:$id,Name:("/"+$name),Image:$image,RestartCount:0,
      State:{Status:$status,StartedAt:"2026-09-01T00:00:00Z"},
      Config:{Image:$image,Env:["SYNTHETIC_SECRET=must-never-be-emitted"],
        Labels:{"com.docker.compose.project":$project,"com.docker.compose.service":$service,
          "org.opencontainers.image.revision":$sha}},
      HostConfig:{RestartPolicy:{Name:"unless-stopped"}},NetworkSettings:{Ports:{},Networks:{}},
      Mounts:[{Type:"bind",Source:$data,Destination:"/www/storage",RW:true}]}'
}
fixture_docker() {
  local operation=${1:-} format='' reference entry project=''
  shift || return 97
  printf '%s\n' "$operation" >> "$case_root/docker-reads.log"
  case "$operation" in
    ps)
      [[ ! -f "$case_root/list-fails" ]] || return 45
      while (($#)); do
        if [[ $1 == --filter ]]; then project=${2#label=com.docker.compose.project=}; shift; fi
        shift
      done
      jq -r --arg project "$project" '.containers[]|
        select($project=="" or .Config.Labels["com.docker.compose.project"]==$project)|.Id' "$case_root/live.json"
      ;;
    inspect)
      if [[ ${1:-} == -f ]]; then format=$2; shift 2; fi
      reference=$1
      entry=$(jq -ce --arg ref "$reference" '.containers[]|select(.Id==$ref or .Name==("/"+$ref))' "$case_root/live.json") || return 1
      case "$format" in
        '') jq -c '[.]' <<< "$entry" ;;
        '{{.Id}}') jq -r .Id <<< "$entry" ;;
        '{{.Image}}') jq -r .Image <<< "$entry" ;;
        '{{ index .Config.Labels "com.docker.compose.project" }}') jq -r '.Config.Labels["com.docker.compose.project"]' <<< "$entry" ;;
        *) echo 'FORBIDDEN_DOCKER_FORMAT' >&2; return 97 ;;
      esac
      ;;
    image)
      operation=$1; shift
      case "$operation" in
        ls)
          [[ ! -f "$case_root/image-list-fails" ]] || return 46
          jq -r '.images[].Id' "$case_root/live.json" ;;
        inspect)
          if [[ ${1:-} == -f ]]; then format=$2; shift 2; fi
          reference=$1
          entry=$(jq -ce --arg ref "$reference" '.images[]|select(.Id==$ref)' "$case_root/live.json") || return 1
          case "$format" in
            '') jq -c '[.]' <<< "$entry" ;;
            '{{ index .Config.Labels "org.opencontainers.image.revision" }}') jq -r '.Config.Labels["org.opencontainers.image.revision"]' <<< "$entry" ;;
            *) echo 'FORBIDDEN_DOCKER_FORMAT' >&2; return 97 ;;
          esac ;;
        *) echo "FORBIDDEN_DOCKER_MUTATION:$operation" >&2; return 97 ;;
      esac ;;
    *) echo "FORBIDDEN_DOCKER_MUTATION:$operation" >&2; return 97 ;;
  esac
}
# Intercept the audit's bounded external Docker metadata adapter; never forward
# to a daemon. All other external commands retain their ordinary local meaning.
command() {
  if [[ ${1:-} == -v && (${2:-} == docker || ${2:-} == systemctl) ]]; then return 0; fi
  if [[ ${1:-} == timeout ]]; then
    [[ $# -ge 6 && $2 == --signal=TERM && $3 == --kill-after=2s && $4 == 10s && $5 == docker ]] || return 97
    shift 5
    fixture_docker "$@"
  else
    builtin command "$@"
  fi
}
timeout() {
  [[ $# == 14 && $1 == --signal=TERM && $2 == --kill-after=2s && $3 == 5s &&
    $4 == systemctl && $5 == show && $6 == caddy.service &&
    $7 == -p && $8 == ActiveState && $9 == -p && ${10} == MainPID &&
    ${11} == -p && ${12} == InvocationID && ${13} == -p && ${14} == ActiveEnterTimestampMonotonic ]] || return 97
  [[ ! -f "$case_root/caddy-inspection-fails" ]] || return 47
  printf '%s\n' ActiveState=active MainPID=42 InvocationID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ActiveEnterTimestampMonotonic=1000
}
xboard_find_compose_anchor() { XBOARD_ANCHOR_CONTAINER=$anchor_id; XBOARD_ANCHOR_WORKDIR=$case_root; }
xboard_find_caddy_upstream() {
  XBOARD_CADDY_FILE=$case_root/Caddyfile
  XBOARD_ACTIVE_UPSTREAM=127.0.0.1:7002
  XBOARD_ACTIVE_PORT=7002
}
xboard_resolve_active_runtime() {
  [[ $1 == 7002 ]] || return 1
  XBOARD_ACTIVE_WEB=$(full_id 2)
  XBOARD_ACTIVE_TOPOLOGY=v2
  [[ ! -f "$case_root/legacy-topology" ]] || XBOARD_ACTIVE_TOPOLOGY=legacy
}
xboard_container_label() {
  [[ $1 == "$(full_id 2)" && $2 == com.docker.compose.project ]] || return 1
  printf 'xboard-v2-333-1\n'
}
retention_startup_references() {
  [[ ! -f "$case_root/startup-fails" ]] || return 48
  printf '{"schema_version":1,"files":[],"image_ids":[],"digests":[],"revisions":[],"references":[]}\n'
}

make_case() {
  local role index=0 containers=''
  case_root="$test_root/$1"
  mkdir -p "$case_root/data" "$case_root/.codex-v2-release/333-1"
  chmod 700 "$case_root/.codex-v2-release"
  printf 'preserved-existing-deployment-lock\n' > "$case_root/.codex-v2-release/deploy.lock"
  chmod 600 "$case_root/.codex-v2-release/deploy.lock"
  for role in redis web ws edge horizon scheduler; do
    ((index+=1))
    containers+="$(raw_container "$(full_id "$index")" "active-$role" xboard-v2-333-1 "$role" running)"$'\n'
  done
  containers+="$(raw_container "$maintenance_id" maintenance none none running)"$'\n'
  containers+="$(raw_container "$anchor_id" anchor legacy xboard exited)"$'\n'
  jq -cn --argjson containers "$(jq -s . <<< "$containers")" --arg id "$app_image" --arg sha "$active_sha" '{
    containers:$containers,images:[{Id:$id,Size:2048,Created:"2020-01-01T00:00:00Z",
      Config:{Labels:{"org.opencontainers.image.revision":$sha,"org.opencontainers.image.source":"https://github.com/Hao-Monster/Xboardme"}},
      RepoTags:[("ghcr.io/hao-monster/xboardme:sha-"+$sha)],RepoDigests:[("ghcr.io/hao-monster/xboardme@"+$id)]}]}' > "$case_root/live.json"
  release_state_create "$case_root/.codex-v2-release/333-1/state.json" release_id 333-1 release_sha "$active_sha" \
    project_name xboard-v2-333-1 traffic_state active_v2 rollback_supported true \
    maintenance_container maintenance app_data_path "$case_root/data" redis_volume_name synthetic-redis
  printf 'reverse_proxy 127.0.0.1:7002\n' > "$case_root/Caddyfile"
  printf 'preserved-user-data\n' > "$case_root/data/record"
  printf 'preserved-user-evidence\n' > "$case_root/.codex-v2-release/.image-audit.user-owned"
  : > "$case_root/docker-reads.log"
}
invoke() (
  set -Eeuo pipefail
  EXPECTED_WORKFLOW_SHA=$workflow_sha
  EXPECTED_ACTIVE_RELEASE_ID=333-1
  EXPECTED_ACTIVE_RELEASE_SHA=$active_sha
  [[ ! -f "$case_root/wrong-expected-sha" ]] || EXPECTED_ACTIVE_RELEASE_SHA=$workflow_sha
  [[ ! -f "$case_root/invalid-workflow-sha" ]] || EXPECTED_WORKFLOW_SHA=shortsha
  source "$script_dir/audit-xboard-images-only.sh"
)
run_case() {
  local original
  local -a preserved=("$case_root/live.json" "$case_root/Caddyfile" "$case_root/data/record" "$case_root/.codex-v2-release/333-1/state.json")
  [[ ! -f "$case_root/.codex-v2-release/deploy.lock" ]] || preserved+=("$case_root/.codex-v2-release/deploy.lock")
  original=$(sha256sum "${preserved[@]}")
  set +e
  ( set -Eeuo pipefail; invoke ) > "$case_root/result.out" 2>&1
  status=$?
  set -e
  [[ $(sha256sum "${preserved[@]}") == "$original" ]]
  [[ -f "$case_root/.codex-v2-release/.image-audit.user-owned" ]]
  [[ $(find "$case_root/.codex-v2-release" -maxdepth 1 -type f -name '.image-*.??????' | wc -l) == 0 ]]
  ! grep -q 'FORBIDDEN_DOCKER_MUTATION\|must-never-be-emitted' "$case_root/result.out"
}
expect_fail() {
  run_case
  ((status != 0)) || { cat "$case_root/result.out" >&2; return 1; }
  ! grep -q '^IMAGE_AUDIT=PASS ' "$case_root/result.out"
}
pass() { ((passed+=1)); printf 'IMAGE_AUDIT_CASE=PASS %s\n' "$1"; }

make_case success
run_case
if ((status != 0)); then cat "$case_root/result.out" >&2; exit 1; fi
grep -q '^IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved$' "$case_root/result.out"
grep -q '^IMAGE_AUDIT_TRAFFIC_STATE=active_v2$' "$case_root/result.out"
grep -q '^IMAGE_AUDIT_ROLLBACK_SUPPORTED=true$' "$case_root/result.out"
grep -q '^IMAGE_AUDIT_LIVE_SIGNALS=missing$' "$case_root/result.out"
[[ $(grep -c '^IMAGE_AUDIT_CONTAINER_SNAPSHOT ' "$case_root/result.out") == 8 ]]
protection=$(sed -n 's/^IMAGE_AUDIT_PROTECTION_B64=//p' "$case_root/result.out" | base64 -d)
jq -e '.upstream=="127.0.0.1:7002" and (.containers|length)==8 and
  all(.containers[];has("Config")|not) and
  any(.containers[];.name=="maintenance" and .status=="running") and
  any(.containers[];.name=="anchor" and .status=="exited")' <<< "$protection" >/dev/null
while IFS= read -r line; do
  jq -e 'has("Config")|not' <<< "$(printf '%s' "${line##*snapshot_b64=}" | base64 -d)" >/dev/null
done < <(grep '^IMAGE_AUDIT_\(CONTAINER\|IMAGE\)_SNAPSHOT ' "$case_root/result.out")
pass active_rollback_six_roles_maintenance_anchor_preserved_and_secret_free

for fault in list-fails image-list-fails startup-fails caddy-inspection-fails legacy-topology wrong-expected-sha invalid-workflow-sha; do
  make_case "$fault"
  touch "$case_root/$fault"
  expect_fail
  pass "${fault}_blocks_audit"
done

make_case transitional
release_state_set "$case_root/.codex-v2-release/333-1/state.json" traffic_state switching
expect_fail
grep -q 'IMAGE_AUDIT_FAIL=transitional_runtime' "$case_root/result.out"
pass transitional_runtime_rejected

make_case unknown_rollback
release_state_set "$case_root/.codex-v2-release/333-1/state.json" rollback_supported unknown
expect_fail
grep -q 'IMAGE_AUDIT_FAIL=unknown_rollback_state' "$case_root/result.out"
pass unknown_rollback_state_rejected

for fault in missing_role stopped_maintenance; do
  make_case "$fault"
  if [[ $fault == missing_role ]]; then expression='.containers|=map(select(.Config.Labels["com.docker.compose.service"]!="scheduler"))'
  else expression='(.containers[]|select(.Name=="/maintenance")|.State.Status)="exited"'; fi
  jq "$expression" "$case_root/live.json" > "$case_root/live.next"
  mv "$case_root/live.next" "$case_root/live.json"
  expect_fail
  grep -q 'IMAGE_AUDIT_FAIL=protection_invalid' "$case_root/result.out"
  pass "${fault}_fails_closed"
done

make_case state_symlink
mv "$case_root/.codex-v2-release/333-1/state.json" "$case_root/state-original.json"
ln -s "$case_root/state-original.json" "$case_root/.codex-v2-release/333-1/state.json"
expect_fail
grep -q 'IMAGE_AUDIT_FAIL=unsafe_active_state' "$case_root/result.out"
pass symlink_state_rejected_without_modification

make_case concurrent_deployment
exec 8<>"$case_root/.codex-v2-release/deploy.lock"
flock -n 8
expect_fail
grep -q 'IMAGE_AUDIT_FAIL=deployment_locked' "$case_root/result.out"
exec 8>&-
pass existing_deployment_lock_blocks_inventory

make_case missing_lock
rm -f -- "$case_root/.codex-v2-release/deploy.lock"
expect_fail
grep -q 'IMAGE_AUDIT_FAIL=unsafe_lock_root' "$case_root/result.out"
[[ ! -e "$case_root/.codex-v2-release/deploy.lock" ]]
pass missing_deployment_lock_rejected_without_creation

make_case unsafe_lock_permissions
chmod 640 "$case_root/.codex-v2-release/deploy.lock"
expect_fail
grep -q 'IMAGE_AUDIT_FAIL=unsafe_lock_root' "$case_root/result.out"
[[ $(stat -c '%a' -- "$case_root/.codex-v2-release/deploy.lock") == 640 ]]
pass unsafe_deployment_lock_permissions_rejected_unchanged

make_case unsafe_lock_root_permissions
chmod 755 "$case_root/.codex-v2-release"
expect_fail
grep -q 'IMAGE_AUDIT_FAIL=unsafe_lock_root' "$case_root/result.out"
[[ $(stat -c '%a' -- "$case_root/.codex-v2-release") == 755 ]]
pass unsafe_lock_root_permissions_rejected_unchanged

for link_type in symbolic hard; do
  make_case "${link_type}_lock"
  mv "$case_root/.codex-v2-release/deploy.lock" "$case_root/user-critical.lock"
  if [[ $link_type == symbolic ]]; then
    ln -s "$case_root/user-critical.lock" "$case_root/.codex-v2-release/deploy.lock"
  else
    ln "$case_root/user-critical.lock" "$case_root/.codex-v2-release/deploy.lock"
  fi
  preserved_target=$(sha256sum "$case_root/user-critical.lock")
  expect_fail
  grep -q 'IMAGE_AUDIT_FAIL=unsafe_lock_root' "$case_root/result.out"
  [[ $(sha256sum "$case_root/user-critical.lock") == "$preserved_target" ]]
  if [[ $link_type == symbolic ]]; then
    [[ -L "$case_root/.codex-v2-release/deploy.lock" ]]
  else
    [[ $(stat -c '%h' -- "$case_root/user-critical.lock") == 2 ]]
  fi
  pass "${link_type}_deployment_lock_rejected_without_truncation"
done

printf 'IMAGE_AUDIT_TESTS=PASS cases=%s offline=true live_runtime=false\n' "$passed"
