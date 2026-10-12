#!/usr/bin/env bash
set -Eeuo pipefail

# OFFLINE ONLY. docker, timeout and sleep are shell functions below. An unknown
# command fails; no real daemon, network, production credential or delay is used.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/release-state.sh"
source "$script_dir/retention-safety.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
passed=0

full_id() { printf '%064x' "$1"; }
old_id=$(full_id 20)
new_id=$(full_id 21)
anchor_id=$(full_id 9)
maintenance_id=$(full_id 8)
app_image="sha256:$(full_id 90)"
old_image="sha256:$(full_id 91)"
eligible_image="sha256:$(full_id 92)"
pending_image="sha256:$(full_id 93)"
active_sha=$(printf 'b%.0s' {1..40})
workflow_sha=$(printf 'c%.0s' {1..40})
old_sha=$(printf 'd%.0s' {1..40})
evidence_sha=$(printf 'e%.0s' {1..64})
fingerprint=$(printf 'f%.0s' {1..64})

raw_container() {
  local id=$1 name=$2 project=$3 service=$4 status=$5 image=$6
  jq -cn --arg id "$id" --arg name "$name" --arg project "$project" --arg service "$service" \
    --arg status "$status" --arg image "$image" --arg revision "$active_sha" --arg data "$case_root/data" '{
      Id:$id,Name:("/"+$name),Image:$image,RestartCount:0,
      State:{Status:$status,StartedAt:"2026-09-01T00:00:00Z"},
      Config:{Image:$image,Env:["SECRET=not-for-logs"],Labels:{"com.docker.compose.project":$project,
        "com.docker.compose.service":$service,"org.opencontainers.image.revision":$revision}},
      HostConfig:{RestartPolicy:{Name:"unless-stopped"}},NetworkSettings:{Ports:{},Networks:{}},
      Mounts:[{Type:"bind",Source:$data,Destination:"/www/storage",RW:true}]
    }'
}
raw_image() {
  local id=$1 revision=$2
  jq -cn --arg id "$id" --arg revision "$revision" '{Id:$id,Size:2048,Created:"2020-01-01T00:00:00Z",
    Config:{Labels:{"org.opencontainers.image.revision":$revision,"org.opencontainers.image.source":"https://github.com/Hao-Monster/Xboardme"}},
    RepoTags:[("ghcr.io/hao-monster/xboardme:sha-"+$revision+"-"+($id|ltrimstr("sha256:")))],
    RepoDigests:[("ghcr.io/hao-monster/xboardme@"+$id)]}'
}
mutate_live() {
  jq "$@" "$case_root/live.json" > "$case_root/live.next"
  mv "$case_root/live.next" "$case_root/live.json"
}
docker() {
  local operation=${1:-} target format entry id project
  shift || true
  case "$operation" in
    ps)
      project=''
      while (($#)); do
        if [[ "$1" == --filter ]]; then project=${2#label=com.docker.compose.project=}; shift; fi
        shift
      done
      jq -r --arg project "$project" '.containers[]|select($project=="" or .Config.Labels["com.docker.compose.project"]==$project)|.Id' "$case_root/live.json" ;;
    inspect|container)
      if [[ "$operation" == container && ${1:-} == rm ]]; then
        id=$2
        [[ $# == 2 && "$id" =~ ^[a-f0-9]{64}$ ]] || return 98
        [[ ! -f "$case_root/delete-fails" ]] || return 33
        jq -e --arg id "$id" 'any(.containers[];.Id==$id and .State.Status=="exited")' "$case_root/live.json" >/dev/null || return 34
        printf 'container-rm %s\n' "$id" >> "$case_root/mutations.log"
        mutate_live --arg id "$id" '.containers|=map(select(.Id!=$id))'
        [[ ! -f "$case_root/delete-then-fail" ]] || return 44
        return
      fi
      [[ "$operation" != container ]] || shift
      format=''
      if [[ ${1:-} == -f ]]; then format=$2; shift 2; fi
      target=${1:-}
      entry=$(jq -ce --arg target "$target" '.containers[]|select(.Id==$target or .Name==("/"+$target))' "$case_root/live.json") || return 1
      case "$format" in
        '') jq -c '[.]' <<< "$entry" ;;
        '{{.Id}}') jq -r .Id <<< "$entry" ;;
        '{{.Image}}') jq -r .Image <<< "$entry" ;;
        '{{ index .Config.Labels "com.docker.compose.project" }}') jq -r '.Config.Labels["com.docker.compose.project"]' <<< "$entry" ;;
        *) echo "UNEXPECTED_INSPECT:$format" >&2; return 99 ;;
      esac ;;
    image)
      operation=${1:-}; shift
      case "$operation" in
        ls) jq -r '.images[].Id' "$case_root/live.json" ;;
        inspect)
          format=''
          if [[ ${1:-} == -f ]]; then format=$2; shift 2; fi
          target=$1
          entry=$(jq -ce --arg target "$target" '.images[]|select(.Id==$target or ((.RepoTags+.RepoDigests)|index($target)!=null))' "$case_root/live.json") || return 1
          case "$format" in
            '') jq -c '[.]' <<< "$entry" ;;
            '{{.Id}}') jq -r .Id <<< "$entry" ;;
            '{{ index .Config.Labels "org.opencontainers.image.revision" }}') jq -r '.Config.Labels["org.opencontainers.image.revision"]' <<< "$entry" ;;
            *) return 99 ;;
          esac ;;
        rm)
          [[ $# == 2 && $1 == --no-prune ]] || return 98
          target=$2
          id=$(docker image inspect -f '{{.Id}}' "$target") || return 1
          [[ ! -f "$case_root/delete-fails" ]] || return 33
          printf 'image-rm %s %s\n' "$id" "$target" >> "$case_root/mutations.log"
          if [[ -f "$case_root/linked-aliases" || "$target" == "$id" ]]; then
            mutate_live --arg id "$id" '.images|=map(select(.Id!=$id))'
          else
            mutate_live --arg id "$id" --arg target "$target" '.images|=map(if .Id==$id then
              .RepoTags-=[ $target ] | .RepoDigests-=[ $target ] else . end)|.images|=map(select((.RepoTags+.RepoDigests)|length>0))'
          fi ;;
        *) return 99 ;;
      esac ;;
    *) echo "UNEXPECTED_DOCKER:$operation" >&2; return 99 ;;
  esac
}
timeout() {
  [[ $1 == --signal=TERM && $2 == --kill-after=10s && $3 == 90s && $4 == docker ]] || return 99
  shift 3
  "$@"
}
sleep() {
  [[ $1 == 10 ]] || return 99
  printf 'observe\n' >> "$case_root/observations.log"
  if [[ -f "$case_root/observation-drift" ]]; then
    mutate_live --arg id "$maintenance_id" '(.containers[]|select(.Id==$id)|.RestartCount)=1'
  fi
  [[ ! -f "$case_root/observation-cancel" ]] || kill -TERM "$BASHPID"
}
xboard_find_compose_anchor() {
  XBOARD_ANCHOR_CONTAINER=$anchor_id
  XBOARD_ANCHOR_WORKDIR=$case_root
}
xboard_find_caddy_upstream() {
  XBOARD_CADDY_FILE=$case_root/Caddyfile
  XBOARD_ACTIVE_UPSTREAM=127.0.0.1:7002
  XBOARD_ACTIVE_PORT=7002
}
xboard_resolve_active_runtime() { XBOARD_ACTIVE_WEB=$(full_id 2); }

context() {
  EXPECTED_RETENTION_RESOURCE_FINGERPRINT=$fingerprint
  EXPECTED_ACTIVE_RELEASE_ID=333-1
  EXPECTED_ACTIVE_RELEASE_SHA=$active_sha
  EXPECTED_WORKFLOW_SHA=$workflow_sha
  RETENTION_REQUIRE_FINALIZED=true
  RETENTION_ACQUIRE_LOCK=true
  RETENTION_MAX_OBJECTS_PER_RUN=5
  RETENTION_OBSERVE_SECONDS=60
  resource_fingerprint=$fingerprint
  active_release_id=333-1
  active_revision=$active_sha
  active_project=xboard-v2-333-1
  active_traffic_state=finalized
  active_rollback_supported=false
  direct_previous_project=''
  workdir=$case_root
  active_container=$(full_id 2)
  active_image_id=$app_image
  active_state_file=$case_root/.codex-v2-release/333-1/state.json
  active_app_data_path=$case_root/data
  active_redis_volume=xboard_redis-data
  xboard_find_compose_anchor
  xboard_find_caddy_upstream
  xboard_resolve_active_runtime
}
make_case() {
  local name=$1 role index=0 containers='' images=''
  case_root="$test_root/$name"
  mkdir -p "$case_root/.codex-v2-release/333-1" "$case_root/.codex-v2-release/111-1" "$case_root/data"
  printf 'reverse_proxy 127.0.0.1:7002\n' > "$case_root/Caddyfile"
  : > "$case_root/mutations.log"
  : > "$case_root/observations.log"
  for role in redis web ws edge horizon scheduler; do
    ((index+=1))
    containers+="$(raw_container "$(full_id "$index")" "active-$role" xboard-v2-333-1 "$role" running "$app_image")"$'\n'
  done
  containers+="$(raw_container "$maintenance_id" current-maintenance none none running "$app_image")"$'\n'
  containers+="$(raw_container "$anchor_id" anchor legacy xboard exited "$app_image")"$'\n'
  containers+="$(raw_container "$old_id" old-web xboard-v2-111-1 web exited "$old_image")"$'\n'
  images+="$(raw_image "$app_image" "$active_sha")"$'\n'
  images+="$(raw_image "$old_image" "$old_sha")"$'\n'
  images+="$(raw_image "$eligible_image" "$old_sha")"$'\n'
  images+="$(raw_image "$pending_image" "$workflow_sha")"$'\n'
  jq -cn --argjson containers "$(jq -s . <<< "$containers")" --argjson images "$(jq -s . <<< "$images")" \
    '{containers:$containers,images:$images}' > "$case_root/live.json"
  context
  release_state_create "$active_state_file" release_id 333-1 release_sha "$active_sha" project_name "$active_project" \
    traffic_state finalized rollback_supported false maintenance_container current-maintenance
  release_state_create "$case_root/.codex-v2-release/111-1/state.json" release_id 111-1 release_sha "$old_sha" \
    project_name xboard-v2-111-1 traffic_state rolled_back
  write_review "$old_id" "$eligible_image"
}
write_review() {
  local container=$1 image=$2 container_entries='[]' image_entries='[]' snapshot_hash old_state_hash
  if [[ -n "$container" ]]; then
    snapshot_hash=$(retention_container_snapshot "$container" | retention_hash)
    old_state_hash=$(retention_hash < "$case_root/.codex-v2-release/111-1/state.json")
    container_entries=$(jq -cn --arg id "$container" --arg hash "$snapshot_hash" --arg state "$old_state_hash" --arg evidence "$evidence_sha" \
      '[{id:$id,snapshot_sha256:$hash,evidence:{release_state_sha256:$state,writable_layer_review_sha256:$evidence,startup_reference_review_sha256:$evidence}}]')
  fi
  if [[ -n "$image" ]]; then
    snapshot_hash=$(retention_image_snapshot "$image" | retention_hash)
    image_entries=$(jq -cn --arg id "$image" --arg hash "$snapshot_hash" --arg evidence "$evidence_sha" \
      '[{id:$id,snapshot_sha256:$hash,evidence:{startup_reference_review_sha256:$evidence}}]')
  fi
  jq -cn --arg id 333-1 --arg sha "$active_sha" --arg fp "$fingerprint" --arg protection "$(retention_live_protection | retention_hash)" \
    --arg evidence "$evidence_sha" --arg pin "ghcr.io/hao-monster/xboardme@$pending_image" --arg pending "$workflow_sha" \
    --argjson containers "$container_entries" --argjson images "$image_entries" '{schema_version:1,status:"approved",operation:"retention_cleanup",
    active_release_id:$id,active_release_sha:$sha,resource_fingerprint:$fp,protection_fingerprint:$protection,
    protected_image_revisions:[$pending],protected_image_digests:[$pin],
    review_evidence:{backup_review_sha256:$evidence,monitoring_review_sha256:$evidence,authentication_review_sha256:$evidence,isolated_docker_review_sha256:$evidence},
    containers:$containers,images:$images}' > "$case_root/review.json"
}
invoke() (
  set -Eeuo pipefail
  context
  RETENTION_REVIEW_MANIFEST_B64=$(base64 -w0 "$case_root/review.json")
  EXPECTED_RETENTION_REVIEW_SHA256=$(retention_hash < "$case_root/review.json")
  inventory=$(mktemp "$case_root/inventory.XXXXXX")
  retention_snapshot_file=$(mktemp "$case_root/snapshots.XXXXXX")
  retention_all_snapshots > "$retention_snapshot_file"
  source "$script_dir/cleanup-xboard-retention.sh"
)
expect_fail() {
  local expected=$1 status
  set +e
  ( set -Eeuo pipefail; invoke ) > "$case_root/result.out" 2>&1
  status=$?
  set -e
  ((status != 0)) || { echo "EXPECTED_FAILURE_MISSING:$expected" >&2; return 1; }
  grep -q "$expected" "$case_root/result.out" || { cat "$case_root/result.out" >&2; return 1; }
}
pass() { ((passed+=1)); printf 'RETENTION_CASE=PASS %s\n' "$1"; }

make_case canary_and_resume
invoke > "$case_root/first.out"
grep -q 'result=pending_batch.*processed=1 remaining=1' "$case_root/first.out"
[[ $(wc -l < "$case_root/mutations.log") == 1 ]]
[[ $(wc -l < "$case_root/observations.log") == 6 ]]
invoke > "$case_root/second.out"
grep -q 'result=complete.*remaining=0' "$case_root/second.out"
count_before=$(wc -l < "$case_root/mutations.log")
invoke > "$case_root/third.out"
[[ $(wc -l < "$case_root/mutations.log") == "$count_before" ]]
jq -e --arg image "$old_image" --arg pending "$pending_image" \
  '(.containers|length)==8 and any(.images[];.Id==$image) and any(.images[];.Id==$pending)' "$case_root/live.json" >/dev/null
pass canary_resume_idempotence_preserve_newly_unreferenced_image

make_case retry_new_container
touch "$case_root/delete-fails"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
rm "$case_root/delete-fails"
mutate_live --argjson added "$(raw_container "$new_id" new-old xboard-v2-111-1 web exited "$old_image")" '.containers+=[$added]'
expect_fail inventory_outside_frozen_plan
[[ ! -s "$case_root/mutations.log" ]]
pass retry_rejects_unreviewed_container

make_case retry_new_image
touch "$case_root/delete-fails"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
rm "$case_root/delete-fails"
mutate_live --argjson added "$(raw_image "sha256:$(full_id 99)" "$old_sha")" '.images+=[$added]'
expect_fail inventory_outside_frozen_plan
[[ ! -s "$case_root/mutations.log" ]]
pass retry_rejects_unreviewed_image

make_case retry_no_drift
touch "$case_root/delete-fails"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
rm "$case_root/delete-fails"
invoke > "$case_root/resume.out"
grep -q 'result=complete' "$case_root/resume.out"
pass failed_delete_resumes_original_subset

make_case removed_before_response_failure
touch "$case_root/delete-then-fail"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
rm "$case_root/delete-then-fail"
invoke > "$case_root/resume.out"
grep -q 'result=absent_after_recorded_intent' "$case_root/resume.out"
[[ $(grep -c '^container-rm ' "$case_root/mutations.log") == 1 ]]
pass recorded_intent_recovers_lost_delete_response

make_case missing_intent
touch "$case_root/delete-fails"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
rm "$case_root/delete-fails"
mutate_live --arg id "$eligible_image" '.images|=map(select(.Id!=$id))'
expect_fail unaccounted_resource_disappearance
[[ ! -s "$case_root/mutations.log" ]]
pass missing_unattempted_object_blocks

make_case linked_aliases
write_review '' "$eligible_image"
touch "$case_root/linked-aliases"
invoke > "$case_root/result.out"
grep -q 'result=already_absent' "$case_root/result.out"
[[ $(wc -l < "$case_root/mutations.log") == 1 ]]
pass linked_alias_disappearance_is_idempotent

make_case alias_drift
touch "$case_root/delete-fails"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
rm "$case_root/delete-fails"
mutate_live --arg id "$eligible_image" '(.images[]|select(.Id==$id)|.RepoTags)+=["ghcr.io/hao-monster/xboardme:new-alias"]'
expect_fail inventory_outside_frozen_plan
[[ ! -s "$case_root/mutations.log" ]]
pass new_image_alias_blocks

make_case mount_drift
mkdir "$case_root/other-data"
mutate_live --arg id "$maintenance_id" --arg source "$case_root/other-data" '(.containers[]|select(.Id==$id)|.Mounts[0].Source)=$source'
expect_fail live_protection_changed
[[ ! -s "$case_root/mutations.log" ]]
pass actual_mount_source_drift_blocks

make_case protected_restart
mutate_live --arg id "$maintenance_id" '(.containers[]|select(.Id==$id)|.RestartCount)=1'
expect_fail live_protection_changed
[[ ! -s "$case_root/mutations.log" ]]
pass current_maintenance_restart_blocks

make_case running_candidate
mutate_live --arg id "$old_id" '(.containers[]|select(.Id==$id)|.State.Status)="running"'
write_review "$old_id" "$eligible_image"
expect_fail container_review_mismatch
[[ ! -s "$case_root/mutations.log" ]]
pass no_running_container_deletion

make_case pending_candidate
write_review '' "$pending_image"
expect_fail image_not_eligible
[[ ! -s "$case_root/mutations.log" ]]
pass pending_digest_and_workflow_revision_protected

make_case pending_digest_after_main_advanced
write_review '' "$pending_image"
jq '.protected_image_revisions=[]' "$case_root/review.json" > "$case_root/review.next"
mv "$case_root/review.next" "$case_root/review.json"
saved_workflow_sha=$workflow_sha
workflow_sha=$(printf 'a%.0s' {1..40})
expect_fail image_not_eligible
workflow_sha=$saved_workflow_sha
[[ ! -s "$case_root/mutations.log" ]]
pass explicitly_pinned_digest_survives_age_and_main_change

make_case image_referenced
mutate_live --arg id "$old_id" --arg image "$eligible_image" '(.containers[]|select(.Id==$id)|.Image)=$image'
write_review '' "$eligible_image"
expect_fail reviewed_image_referenced
[[ ! -s "$case_root/mutations.log" ]]
pass all_container_image_references_protected

make_case image_untrusted_source
mutate_live --arg id "$eligible_image" '(.images[]|select(.Id==$id)|.Config.Labels["org.opencontainers.image.source"])="https://example.invalid/third-party"'
write_review '' "$eligible_image"
expect_fail image_not_eligible
[[ ! -s "$case_root/mutations.log" ]]
pass untrusted_image_source_rejected

make_case image_unknown_alias
mutate_live --arg id "$eligible_image" '(.images[]|select(.Id==$id)|.RepoTags)+=["third-party:keep"]'
write_review '' "$eligible_image"
expect_fail image_not_eligible
[[ ! -s "$case_root/mutations.log" ]]
pass unknown_image_alias_rejected

make_case image_young
mutate_live --arg id "$eligible_image" --arg created "$(date -u +%FT%TZ)" '(.images[]|select(.Id==$id)|.Created)=$created'
write_review '' "$eligible_image"
expect_fail image_not_eligible
[[ ! -s "$case_root/mutations.log" ]]
pass younger_than_seven_days_rejected

make_case blocked_manifest
jq '.status="blocked"' "$case_root/review.json" > "$case_root/review.next"
mv "$case_root/review.next" "$case_root/review.json"
expect_fail reviewed_manifest_required
[[ ! -s "$case_root/mutations.log" ]]
pass unapproved_manifest_zero_mutations

make_case tampered_plan
touch "$case_root/delete-fails"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
printf ' ' >> "$case_root/.codex-v2-release/333-1/retention-cleanup-plan.json"
expect_fail retry_plan_integrity_mismatch
[[ ! -s "$case_root/mutations.log" ]]
pass durable_plan_integrity_checked

make_case observation_failure
touch "$case_root/observation-drift"
expect_fail live_protection_changed
grep -q 'RETENTION_FAILURE_PROTECTION=FAIL' "$case_root/result.out"
[[ $(wc -l < "$case_root/mutations.log") == 1 ]]
[[ $(release_state_get "$active_state_file" retention_cleanup_status) == failed ]]
pass partial_deletion_failure_evidence_preserved

make_case cancellation
touch "$case_root/observation-cancel"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
[[ $(wc -l < "$case_root/mutations.log") == 1 ]]
[[ $(release_state_get "$active_state_file" retention_cleanup_status) == failed ]]
pass cancellation_stops_with_durable_partial_evidence

make_case protection_caddy_change
printf '# unexpected config change\n' >> "$case_root/Caddyfile"
expect_fail live_protection_changed
[[ ! -s "$case_root/mutations.log" ]]
pass caddy_configuration_drift_blocks

make_case secret_free_snapshot
snapshot=$(retention_container_snapshot "$old_id")
[[ "$snapshot" != *not-for-logs* && "$snapshot" == *'"Source"'* && "$snapshot" == *'"RW":true'* ]]
pass full_mount_metadata_without_secrets

make_case protected_tmpfs
mutate_live --arg id "$maintenance_id" '(.containers[]|select(.Id==$id)|.Mounts)+=[{Type:"tmpfs",Source:"",Destination:"/config",RW:true}]'
snapshot=$(retention_live_protection)
jq -e 'any(.containers[].mounts[];.Type=="tmpfs" and .source_identity=="tmpfs")' <<< "$snapshot" >/dev/null
pass current_maintenance_tmpfs_is_not_a_host_volume

make_case history_changed_on_retry
touch "$case_root/delete-fails"
expect_fail RETENTION_FAILURE_PROTECTION=PASS
rm "$case_root/delete-fails"
release_state_set "$case_root/.codex-v2-release/111-1/state.json" traffic_state active_v2
expect_fail candidate_history_changed
[[ ! -s "$case_root/mutations.log" ]]
pass changed_historical_release_evidence_blocks_retry

make_case unresolved_image_cleanup
printf '{"status":"HOLD"}\n' > "$case_root/.codex-v2-release/image-cleanup-gate.json"
state_before=$(sha256sum "$active_state_file")
expect_fail cleanup_admission_helper_missing
[[ ! -s "$case_root/mutations.log" ]]
[[ ! -e "$case_root/.codex-v2-release/333-1/retention-cleanup-plan.json" ]]
[[ "$(sha256sum "$active_state_file")" == "$state_before" ]]
pass unresolved_image_cleanup_blocks_before_intent

if retention_review_validate "$script_dir/../release/retention-review.json"; then
  echo 'DEFAULT_MANIFEST_MUST_NOT_AUTHORIZE_DELETION' >&2
  exit 1
fi
pass checked_in_default_is_blocked
echo "RETENTION_CLEANUP_TEST=PASS cases=$passed real_docker=false"
