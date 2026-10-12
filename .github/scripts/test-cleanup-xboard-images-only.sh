#!/usr/bin/env bash
set -Eeuo pipefail

# Offline executor contract tests. Docker and continuity are explicit shell
# fakes; unknown Docker operations fail instead of reaching a real daemon.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-safety.sh"
# Executor decisions retain the existing exact fake inventory boundary; the
# production inventory/watchdog is independently tested against a CLI fixture.
images_only_all_snapshots() { retention_all_snapshots; }
source "$script_dir/images-only-approval.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
passed=0
full_id() { printf '%064x' "$1"; }
candidate="sha256:$(full_id 91)"
protected="sha256:$(full_id 90)"
pending="sha256:$(full_id 92)"
old_sha=$(printf 'b%.0s' {1..40})
active_sha=$(printf 'c%.0s' {1..40})
workflow_sha=$(printf 'd%.0s' {1..40})
evidence_sha=$(printf 'e%.0s' {1..64})
resource_sha=$(printf 'f%.0s' {1..64})

raw_image() {
  jq -cn --arg id "$1" --arg sha "$2" '{Id:$id,Size:2048,Created:"2020-01-01T00:00:00Z",
    Config:{Labels:{"org.opencontainers.image.revision":$sha,"org.opencontainers.image.source":"https://github.com/Hao-Monster/Xboardme"}},
    RepoTags:[("ghcr.io/hao-monster/xboardme:sha-"+$sha)],RepoDigests:[("ghcr.io/hao-monster/xboardme@"+$id)]}'
}
mutate_live() {
  jq "$@" "$case_root/live.json" > "$case_root/live.next"
  mv -- "$case_root/live.next" "$case_root/live.json"
}
docker() {
  local command=${1:-} operation format='' reference item id
  shift || true
  case "$command" in
    ps) jq -r '.containers[].Id' "$case_root/live.json" ;;
    inspect)
      reference=${1:-}
      jq -ce --arg id "$reference" '[.containers[]|select(.Id==$id)]|select(length==1)' "$case_root/live.json" ;;
    image)
      operation=${1:-}; shift
      case "$operation" in
        ls)
          if [[ -f "$case_root/daemon-fails-after-delete" && -s "$case_root/mutations.log" ]]; then return 42; fi
          jq -r '.images[].Id' "$case_root/live.json" ;;
        inspect)
          if [[ ${1:-} == -f ]]; then format=$2; shift 2; fi
          reference=$1
          item=$(jq -ce --arg ref "$reference" '.images[]|select(.Id==$ref or ((.RepoTags+.RepoDigests)|index($ref)!=null))' "$case_root/live.json") || return 1
          case "$format" in
            '') jq -c '[.]' <<< "$item" ;;
            '{{.Id}}') jq -r .Id <<< "$item" ;;
            *) echo "UNEXPECTED_FORMAT:$format" >&2; return 98 ;;
          esac ;;
        rm)
          [[ $# == 2 && $1 == --no-prune ]] || { echo 'UNSAFE_IMAGE_REMOVE' >&2; return 98; }
          reference=$2
          id=$(docker image inspect -f '{{.Id}}' "$reference") || return 1
          [[ "$id" == "$candidate" ]] || { echo 'UNREVIEWED_IMAGE_REMOVE' >&2; return 98; }
          [[ -f "$case_root/intent" ]] || { echo 'REMOVE_BEFORE_INTENT' >&2; return 98; }
          [[ ! -f "$case_root/delete-fails" ]] || return 33
          printf '%s\n' "$reference" >> "$case_root/mutations.log"
          if [[ -f "$case_root/linked-aliases" || "$reference" == "$id" ]]; then
            mutate_live --arg id "$id" '.images|=map(select(.Id!=$id))'
          else
            mutate_live --arg id "$id" --arg ref "$reference" '.images|=map(if .Id==$id then
              .RepoTags-=[$ref]|.RepoDigests-=[$ref] else . end)|.images|=map(select((.RepoTags+.RepoDigests)|length>0))'
          fi
          if [[ -f "$case_root/new-alias-after-delete" ]]; then
            mutate_live --arg id "$id" '(.images[]|select(.Id==$id)|.RepoTags)+=["ghcr.io/hao-monster/xboardme:unreviewed"]'
          fi
          if [[ -f "$case_root/reference-after-delete" ]]; then
            mutate_live --arg id "$id" '(.containers[0].Image)=$id'
          fi
          if [[ -f "$case_root/state-after-delete" ]]; then printf 'changed\n' >> "$active_state_file"; fi
          if [[ -f "$case_root/startup-after-delete" ]]; then
            jq '.references+=["ghcr.io/hao-monster/xboardme:new-startup"]' "$case_root/startup.json" > "$case_root/startup.next"
            mv "$case_root/startup.next" "$case_root/startup.json"
          fi
          if [[ -f "$case_root/protection-after-delete" ]]; then mutate_live '.protection.restart_count=1'; fi
          if [[ -f "$case_root/plan-after-delete" ]]; then printf 'corrupt\n' >> "$image_cleanup_plan"; fi
          if [[ -f "$case_root/term-after-delete" ]]; then kill -TERM "$BASHPID"; fi
          [[ ! -f "$case_root/delete-response-lost" ]] || return 44
          ;;
        *) echo "UNEXPECTED_IMAGE_COMMAND:$operation" >&2; return 98 ;;
      esac ;;
    *) echo "FORBIDDEN_DOCKER_COMMAND:$command" >&2; return 98 ;;
  esac
}
timeout() {
  [[ $1 == --signal=TERM && $2 == --kill-after=10s && $3 == 90s && $4 == docker ]] || return 98
  shift 3
  "$@"
}

# Only the external monitoring boundary is stubbed. The production executor
# still evaluates real canonical inventory, candidate and startup hashes.
retention_live_protection() { jq -cS '.protection' "$case_root/live.json"; }
retention_startup_references() { jq -cS . "$case_root/startup.json"; }
retention_continuity_begin() {
  printf 'begin\n' >> "$case_root/monitor.log"
  if [[ "${image_cleanup_review_temporary:-false}" == true ]]; then
    [[ "$(stat -c %a -- "$1")" == 600 ]] || return 69
  fi
  [[ ! -e "$case_root/gate" ]] || return 70
  printf 'RUNNING\n' > "$case_root/gate"
  [[ ! -f "$case_root/baseline-fails" ]] || return 71
}
retention_continuity_check() {
  printf 'check\n' >> "$case_root/monitor.log"
  [[ ! -f "$case_root/monitor-fails" ]] || return 72
  if [[ -f "$case_root/monitor-fails-after-delete" && -s "$case_root/mutations.log" ]]; then return 72; fi
}
retention_continuity_intent() {
  [[ ! -f "$case_root/intent-fails" ]] || return 73
  [[ $1 == "$candidate" && ! -e "$case_root/intent" ]] || return 73
  printf '%s\n' "$1" > "$case_root/intent"
  printf 'intent\n' >> "$case_root/monitor.log"
}
retention_continuity_observe() {
  [[ $1 == "$candidate" ]] || return 74
  jq -e --arg id "$1" 'all(.images[];.Id!=$id)' "$case_root/live.json" >/dev/null || return 74
  printf 'observe\n' >> "$case_root/monitor.log"
  [[ ! -f "$case_root/observation-fails" ]] || return 75
}
retention_continuity_complete() {
  printf 'complete\n' >> "$case_root/monitor.log"
  [[ ! -f "$case_root/complete-fails" ]] || return 76
  printf 'COMPLETE\n' > "$case_root/gate"
}
retention_continuity_hold() { printf 'HOLD\n' > "$case_root/gate"; printf 'hold:%s\n' "$1" >> "$case_root/monitor.log"; }

context() {
  EXPECTED_RETENTION_RESOURCE_FINGERPRINT=$resource_sha
  EXPECTED_ACTIVE_RELEASE_ID=333-1
  EXPECTED_ACTIVE_RELEASE_SHA=$active_sha
  EXPECTED_WORKFLOW_SHA=$workflow_sha
  RETENTION_ACQUIRE_LOCK=true
  RETENTION_REQUIRE_FINALIZED=false
  RETENTION_IMAGE_MIN_AGE_SECONDS=604800
  resource_fingerprint=$resource_sha
  active_release_id=333-1
  active_revision=$active_sha
  active_project=xboard-v2-333-1
  active_container=$(full_id 2)
  active_image_id=$protected
  active_traffic_state=active_v2
  active_rollback_supported=true
  direct_previous_project=xboard-v2-222-1
  workdir=$case_root
  active_state_file=$case_root/.codex-v2-release/333-1/state.json
  retention_snapshot_file=$case_root/inventory.json
  retention_startup_file=$case_root/startup-snapshot.json
  review_file=$case_root/review.json
  EXPECTED_RETENTION_REVIEW_SHA256=$(retention_hash < "$review_file")
  [[ ! -f "$case_root/wrong-review-hash" ]] || EXPECTED_RETENTION_REVIEW_SHA256=$evidence_sha
}
write_review() {
  local selected=${1:-$candidate}
  jq -cn --arg id "$selected" --arg image_hash "$(retention_image_snapshot "$selected" | retention_hash)" \
    --arg sha "$active_sha" --arg fingerprint "$resource_sha" --arg protection "$(retention_live_protection | retention_hash)" \
    --arg startup "$(retention_startup_references | retention_hash)" --arg workflow "$workflow_sha" \
    --arg pending "ghcr.io/hao-monster/xboardme@$pending" --arg evidence "$evidence_sha" --argjson now "$(date -u +%s)" '{
      schema_version:2,status:"approved",operation:"image_cleanup",active_release_id:"333-1",active_release_sha:$sha,
      resource_fingerprint:$fingerprint,protection_fingerprint:$protection,
      protected_image_revisions:[$workflow,$sha],protected_image_digests:[$pending],containers:[],
      max_image_size_bytes:536870912,audit_completed_at_epoch:$now,approval_expires_at_epoch:($now+3600),
      monitoring:{executor_boundary_fixture:true},
      images:[{id:$id,snapshot_sha256:$image_hash,evidence:{startup_reference_review_sha256:$startup}}],
      review_evidence:{backup_review_sha256:$evidence,monitoring_review_sha256:$evidence,
        authentication_review_sha256:$evidence,isolated_docker_review_sha256:$evidence}}
    ' > "$case_root/review.json"
  chmod 600 "$case_root/review.json"
}
make_case() {
  case_root="$test_root/$1"
  mkdir -p "$case_root/.codex-v2-release/333-1"
  chmod 700 "$case_root/.codex-v2-release"
  printf '{"traffic_state":"active_v2","rollback_supported":true}\n' > "$case_root/.codex-v2-release/333-1/state.json"
  chmod 600 "$case_root/.codex-v2-release/333-1/state.json"
  printf '{"schema_version":1,"files":[],"image_ids":[],"digests":[],"revisions":[],"references":[]}\n' > "$case_root/startup.json"
  jq -cn --arg id "$(full_id 2)" --arg protected "$protected" \
    --argjson image "$(raw_image "$candidate" "$old_sha")" --argjson keep "$(raw_image "$protected" "$active_sha")" \
    --argjson pending "$(raw_image "$pending" "$workflow_sha")" '{
      containers:[{Id:$id,Name:"/active-web",Image:$protected,RestartCount:0,
        State:{Status:"running",StartedAt:"2026-01-01T00:00:00Z"},
        Config:{Image:$protected,Labels:{}},HostConfig:{},NetworkSettings:{Ports:{},Networks:{}},Mounts:[]}],
      images:[$image,$keep,$pending],protection:{active_id:$id,upstream:"127.0.0.1:7002",restart_count:0}}
    ' > "$case_root/live.json"
  : > "$case_root/mutations.log"
  : > "$case_root/monitor.log"
  write_review
  context
  retention_all_snapshots > "$retention_snapshot_file"
  retention_startup_references > "$retention_startup_file"
  cp -- "$active_state_file" "$case_root/original-state.json"
}
invoke() (
  set -Eeuo pipefail
  context
  if [[ -f "$case_root/streamed-review" ]]; then
    RETENTION_REVIEW_MANIFEST_B64=$(base64 -w0 "$review_file")
    review_file=''
    [[ ! -f "$case_root/invalid-base64" ]] || RETENTION_REVIEW_MANIFEST_B64='!not-base64!'
  fi
  if [[ ! -f "$case_root/no-lock" ]]; then
    exec 9>"$workdir/.codex-v2-release/deploy.lock"
    chmod 600 "$workdir/.codex-v2-release/deploy.lock"
    flock -n 9
    if [[ -f "$case_root/wrong-lock" ]]; then
      exec 9>"$workdir/wrong.lock"
      flock -n 9
    fi
  fi
  if [[ -f "$case_root/audit-temporaries" ]]; then
    retention_snapshot_file=$(mktemp "$workdir/.codex-v2-release/.image-inventory.XXXXXX")
    retention_startup_file=$(mktemp "$workdir/.codex-v2-release/.image-startup.XXXXXX")
    inventory=$(mktemp "$workdir/.codex-v2-release/.image-audit.XXXXXX")
    cp "$case_root/inventory.json" "$retention_snapshot_file"
    cp "$case_root/startup-snapshot.json" "$retention_startup_file"
    printf 'fixture\n' > "$inventory"
  fi
  source "$script_dir/${IMAGE_ONLY_EXECUTOR:-cleanup-xboard-images-only.sh}"
)
run_case() {
  set +e
  ( set -Eeuo pipefail; invoke ) > "$case_root/result.out" 2>&1
  status=$?
  set -e
}
expect_pass() {
  run_case
  if ((status != 0)); then cat "$case_root/result.out" >&2; return 1; fi
  grep -q '^IMAGE_CLEANUP=PASS result=complete ' "$case_root/result.out"
  cmp "$active_state_file" "$case_root/original-state.json"
}
expect_fail() {
  local expected=$1
  run_case
  ((status != 0)) || { echo "EXPECTED_FAILURE:$expected" >&2; return 1; }
  if ! grep -q "$expected" "$case_root/result.out"; then cat "$case_root/result.out" >&2; return 1; fi
}
pass() { ((passed+=1)); printf 'IMAGE_ONLY_CASE=PASS %s\n' "$1"; }

make_case active_unfinalized
expect_pass
jq -e --arg id "$candidate" --arg keep "$protected" --arg pending "$pending" \
  '(.containers|length)==1 and all(.images[];.Id!=$id) and any(.images[];.Id==$keep) and any(.images[];.Id==$pending)' "$case_root/live.json" >/dev/null
[[ $(< "$case_root/gate") == COMPLETE ]]
[[ $(grep -c '^intent$' "$case_root/monitor.log") == 1 && $(grep -c '^observe$' "$case_root/monitor.log") == 1 ]]
pass active_rollback_preserved_without_finalize

make_case audit_temporary_cleanup
touch "$case_root/audit-temporaries"
printf 'user evidence\n' > "$workdir/.codex-v2-release/.image-audit.user-owned"
expect_pass
[[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-inventory.*' | wc -l) == 0 ]]
[[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-startup.*' | wc -l) == 0 ]]
[[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-audit.??????' | wc -l) == 0 ]]
[[ -f "$workdir/.codex-v2-release/.image-audit.user-owned" ]]
pass exact_audit_temporaries_removed_without_touching_evidence

make_case streamed_review
touch "$case_root/streamed-review" "$case_root/audit-temporaries"
printf 'user evidence\n' > "$workdir/.codex-v2-release/.image-review.user-owned"
expect_pass
[[ -f "$case_root/review.json" && -f "$workdir/.codex-v2-release/.image-review.user-owned" ]]
[[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-review.??????' | wc -l) == 0 ]]
pass streamed_review_loaded_privately_and_exact_temporary_removed

for fault in invalid-base64 wrong-review-hash; do
  make_case "streamed_$fault"
  touch "$case_root/streamed-review" "$case_root/audit-temporaries" "$case_root/$fault"
  expect_fail review_load_failed
  [[ -f "$case_root/review.json" && ! -s "$case_root/mutations.log" ]]
  [[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-review.??????' | wc -l) == 0 ]] || {
    cat "$case_root/result.out" >&2; exit 1;
  }
  [[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-inventory.??????' | wc -l) == 0 ]]
  [[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-startup.??????' | wc -l) == 0 ]]
  [[ $(find "$workdir/.codex-v2-release" -maxdepth 1 -type f -name '.image-audit.??????' | wc -l) == 0 ]]
  pass "streamed_${fault}_cleans_only_exact_temporaries"
done

make_case second_image
jq '.images += [.images[0]]' "$review_file" > "$case_root/review.next"; mv "$case_root/review.next" "$review_file"
expect_fail 'exactly_one_image_required\|review_not_approved'
[[ ! -s "$case_root/mutations.log" ]]
pass multiple_images_rejected

make_case container_entry
jq --arg id "$(full_id 5)" '.containers=[{id:$id}]' "$review_file" > "$case_root/review.next"; mv "$case_root/review.next" "$review_file"
expect_fail 'containers_forbidden\|review_not_approved'
pass container_scope_rejected

make_case no_lock
touch "$case_root/no-lock"
expect_fail deployment_lock_required
pass real_deployment_lock_required

make_case wrong_lock
touch "$case_root/wrong-lock"
expect_fail deployment_lock_mismatch
pass different_lock_inode_rejected

make_case altered_review
touch "$case_root/wrong-review-hash"
expect_fail review_hash_mismatch
pass raw_review_hash_bound

for period in expired future; do
  make_case "$period"
  if [[ "$period" == expired ]]; then expression='.audit_completed_at_epoch-=1000|.approval_expires_at_epoch=(.audit_completed_at_epoch+999)'
  else expression='.audit_completed_at_epoch+=1000|.approval_expires_at_epoch=(.audit_completed_at_epoch+3600)'; fi
  jq "$expression" "$review_file" > "$case_root/review.next"; mv "$case_root/review.next" "$review_file"
  expect_fail approval_expired
  [[ ! -s "$case_root/mutations.log" ]]
  pass "${period}_approval_rejected"
done

make_case oversized
jq '.max_image_size_bytes=1024' "$review_file" > "$case_root/review.next"; mv "$case_root/review.next" "$review_file"
expect_fail image_size_exceeds_review
pass approved_size_ceiling_enforced

make_case excessive_aliases
mutate_live --arg id "$candidate" '(.images[]|select(.Id==$id)|.RepoTags)=[range(8)|"ghcr.io/hao-monster/xboardme:alias-"+tostring]'
retention_all_snapshots > "$retention_snapshot_file"
write_review
expect_fail invalid_alias_count
pass excessive_aliases_rejected

make_case referenced
mutate_live --arg id "$candidate" '.containers[0].Image=$id'
retention_all_snapshots > "$retention_snapshot_file"
expect_fail image_is_container_referenced
[[ ! -s "$case_root/mutations.log" ]]
pass all_container_references_protected

for field in image_ids digests revisions references; do
  make_case "startup_$field"
  value=$candidate
  [[ "$field" != digests ]] || value="ghcr.io/hao-monster/xboardme@$candidate"
  [[ "$field" != revisions ]] || value=$old_sha
  [[ "$field" != references ]] || value="ghcr.io/hao-monster/xboardme:sha-$old_sha"
  jq --arg field "$field" --arg value "$value" '.[$field]=[$value]' "$case_root/startup.json" > "$case_root/startup.next"
  mv "$case_root/startup.next" "$case_root/startup.json"
  retention_startup_references > "$retention_startup_file"
  write_review
  expect_fail image_has_startup_reference
  [[ ! -s "$case_root/mutations.log" ]]
  pass "startup_${field}_protected"
done

make_case startup_cross_repository_digest
jq --arg digest "registry.example.invalid/retained-xboard@$candidate" '.digests=[$digest]|.references=[$digest]' \
  "$case_root/startup.json" > "$case_root/startup.next"
mv "$case_root/startup.next" "$case_root/startup.json"
retention_startup_references > "$retention_startup_file"
write_review
expect_fail image_has_startup_reference
[[ ! -s "$case_root/mutations.log" ]]
pass startup_same_manifest_under_different_repository_protected

make_case approved_cross_repository_digest
jq --arg digest "ghcr.io/fenghaoyun-monster/xboardme@$candidate" '.protected_image_digests=[$digest]' \
  "$review_file" > "$case_root/review.next"
mv "$case_root/review.next" "$review_file"
expect_fail protected_manifest_digest
[[ ! -s "$case_root/mutations.log" ]]
pass approved_same_manifest_under_different_repository_protected

make_case pending_image
write_review "$pending"
expect_fail image_not_eligible
pass pending_digest_protected

make_case snapshot_drift
mutate_live --arg id "$candidate" '(.images[]|select(.Id==$id)|.RepoTags)+=["ghcr.io/hao-monster/xboardme:late"]'
expect_fail inventory_changed_before_cleanup
pass unreviewed_alias_before_start_rejected

make_case baseline_failure
touch "$case_root/baseline-fails"
expect_fail continuity_baseline_failed
[[ ! -s "$case_root/mutations.log" && $(< "$case_root/gate") == HOLD ]]
pass no_delete_without_baseline

make_case linked_aliases
touch "$case_root/linked-aliases"
expect_pass
[[ $(wc -l < "$case_root/mutations.log") == 1 ]]
pass aliases_disappearing_together_do_not_expand_scope

for fault in delete-fails delete-response-lost new-alias-after-delete reference-after-delete startup-after-delete state-after-delete protection-after-delete plan-after-delete daemon-fails-after-delete monitor-fails intent-fails monitor-fails-after-delete observation-fails complete-fails term-after-delete; do
  make_case "$fault"
  touch "$case_root/$fault"
  expect_fail 'IMAGE_CLEANUP_\(FAIL\|INTERRUPTED\)'
  [[ $(< "$case_root/gate") == HOLD ]]
  count_before=$(wc -l < "$case_root/mutations.log")
  rm -- "$case_root/$fault"
  expect_fail 'prior_execution_requires_review\|inventory_changed_before_cleanup\|active_state_changed\|startup_references_changed'
  [[ $(wc -l < "$case_root/mutations.log") == "$count_before" ]]
  pass "${fault}_holds_without_automatic_resume"
done

make_case successful_replay
expect_pass
count_before=$(wc -l < "$case_root/mutations.log")
expect_fail 'prior_execution_requires_review\|inventory_changed_before_cleanup'
[[ $(wc -l < "$case_root/mutations.log") == "$count_before" ]]
pass successful_manifest_not_replayed
make_passive_review() {
  jq --slurpfile inventory "$retention_snapshot_file" --arg revision "$old_sha" --arg digest "ghcr.io/hao-monster/xboardme@$candidate" '
    .schema_version=3 | .monitoring={mode:"passive_runtime_v1",
      protected_containers:[$inventory[0].containers[]|{id,status,started_at,restart_count}]} |
    .review_evidence={scope_review_sha256:.review_evidence.monitoring_review_sha256,
      isolated_docker_review_sha256:.review_evidence.isolated_docker_review_sha256} |
    .images[0].revision=$revision | .images[0].restore_digest=$digest' "$review_file" > "$case_root/review.next"
  mv -- "$case_root/review.next" "$review_file"
}
make_case passive_success
make_passive_review
expect_pass
pass passive_precise_scope_success
for fault in digest revision monitor_missing monitor_extra; do
  make_case "passive_$fault"
  make_passive_review
  case "$fault" in
    digest) expression='.images[0].restore_digest="ghcr.io/hao-monster/xboardme@sha256:"+("7"*64)' ;;
    revision) expression='.images[0].revision=("7"*40)' ;;
    monitor_missing) expression='.monitoring.protected_containers=[]' ;;
    monitor_extra) expression='.monitoring.protected_containers+=.monitoring.protected_containers' ;;
  esac
  jq "$expression" "$review_file" > "$case_root/review.next"
  mv -- "$case_root/review.next" "$review_file"
  expect_fail 'restore_identity_not_in_audit\|monitoring_inventory_mismatch'
  [[ ! -s "$case_root/mutations.log" ]]
  pass "passive_${fault}_rejected_before_mutation"
done
echo "IMAGE_ONLY_TESTS=PASS cases=$passed"
