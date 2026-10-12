#!/usr/bin/env bash
set -Eeuo pipefail

# Stream after the locked lightweight audit and continuity helpers. This is a
# different admission path from finalize: every container, volume, directory,
# release-state file and direct rollback generation remains untouched.
image_cleanup_fail() { echo "IMAGE_CLEANUP_FAIL=$1" >&2; return 1; }

: "${EXPECTED_WORKFLOW_SHA:?required}"
: "${EXPECTED_ACTIVE_RELEASE_ID:?required}"
: "${EXPECTED_ACTIVE_RELEASE_SHA:?required}"
: "${EXPECTED_RETENTION_RESOURCE_FINGERPRINT:?required}"
: "${EXPECTED_RETENTION_REVIEW_SHA256:?required}"
: "${RETENTION_IMAGE_MIN_AGE_SECONDS:=604800}"
review_file=${RETENTION_REVIEW_FILE:-${review_file:-}}

for helper in retention_images_review_validate retention_images_review_fresh retention_images_review_load retention_hash \
  retention_image_snapshot retention_container_snapshot retention_all_snapshots retention_image_eligible \
  retention_inventory_within_plan retention_live_protection retention_startup_references \
  retention_continuity_begin retention_continuity_check retention_continuity_intent \
  retention_continuity_observe retention_continuity_complete retention_continuity_hold; do
  declare -F "$helper" >/dev/null || image_cleanup_fail "missing_helper:$helper"
done
for tool in basename chmod date dirname docker flock grep jq ln mktemp realpath rm sha256sum stat timeout; do
  command -v "$tool" >/dev/null || image_cleanup_fail "missing_tool:$tool"
done
[[ "$EXPECTED_WORKFLOW_SHA" =~ ^[a-f0-9]{40}$ && "$EXPECTED_ACTIVE_RELEASE_SHA" =~ ^[a-f0-9]{40}$ &&
   "$EXPECTED_ACTIVE_RELEASE_ID" =~ ^[0-9]+-[0-9]+$ &&
   "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" =~ ^[a-f0-9]{64}$ &&
   "$EXPECTED_RETENTION_REVIEW_SHA256" =~ ^[a-f0-9]{64}$ ]] || image_cleanup_fail invalid_identity
[[ "$RETENTION_IMAGE_MIN_AGE_SECONDS" =~ ^[0-9]{1,9}$ ]] || image_cleanup_fail invalid_image_min_age
((RETENTION_IMAGE_MIN_AGE_SECONDS >= 604800)) || image_cleanup_fail image_min_age_below_seven_days
[[ "${active_release_id:-}" == "$EXPECTED_ACTIVE_RELEASE_ID" &&
   "${active_revision:-}" == "$EXPECTED_ACTIVE_RELEASE_SHA" &&
   "${active_project:-}" == "xboard-v2-$EXPECTED_ACTIVE_RELEASE_ID" ]] || image_cleanup_fail active_identity_mismatch
[[ "${resource_fingerprint:-}" == "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" ]] || image_cleanup_fail resource_fingerprint_mismatch
[[ -n "${workdir:-}" && "$workdir" == /* && "$(realpath -e -- "$workdir")" == "$workdir" ]] || image_cleanup_fail invalid_workdir
image_cleanup_root="$workdir/.codex-v2-release"
[[ -d "$image_cleanup_root" && ! -L "$image_cleanup_root" &&
   "$(realpath -e -- "$image_cleanup_root")" == "$image_cleanup_root" ]] || image_cleanup_fail invalid_release_root

image_cleanup_assert_lock() {
  [[ "${RETENTION_ACQUIRE_LOCK:-false}" == true && -f "$image_cleanup_root/deploy.lock" &&
     ! -L "$image_cleanup_root/deploy.lock" && -e "/proc/$BASHPID/fd/9" ]] || {
    image_cleanup_fail deployment_lock_required; return 1;
  }
  [[ "$(stat -Lc '%d:%i' -- "/proc/$BASHPID/fd/9")" == \
     "$(stat -Lc '%d:%i' -- "$image_cleanup_root/deploy.lock")" ]] || {
    image_cleanup_fail deployment_lock_mismatch; return 1;
  }
  # Keep the existing open file description. Never close/reopen fd9 between
  # audit, baseline, deletion and observation.
  flock -n 9 || { image_cleanup_fail deployment_lock_required; return 1; }
}
image_cleanup_assert_lock
image_cleanup_monitor_started=false
image_cleanup_done=false
image_cleanup_intent_recorded=false
image_cleanup_plan_hash=''
image_cleanup_review_temporary=false
# EXIT can run after a sourcing function's local scope has unwound on failure.
declare -ga image_cleanup_audit_temporaries=()
declare -gA image_cleanup_audit_temporary_ids=()
image_cleanup_track_temporary() {
  local path=$1 prefix=$2 filename suffix
  case "$prefix" in
    .image-inventory.|.image-startup.|.image-audit.|.image-review.|.image-cleanup-current.|.image-cleanup-plan.) ;;
    *) return 1 ;;
  esac
  [[ -n "$path" && -f "$path" && ! -L "$path" &&
     "$(dirname -- "$path")" == "$image_cleanup_root" &&
     "$(realpath -e -- "$path")" == "$path" ]] || return 1
  filename=$(basename -- "$path")
  suffix=${filename#"$prefix"}
  [[ "$filename" == "$prefix"* && "$suffix" =~ ^[a-zA-Z0-9]{6}$ ]] || return 1
  image_cleanup_audit_temporaries+=("$path")
  image_cleanup_audit_temporary_ids["$path"]=$(stat -Lc '%d:%i' -- "$path")
}
for image_cleanup_temporary_spec in \
  ".image-inventory.:${retention_snapshot_file:-}" \
  ".image-startup.:${retention_startup_file:-}" \
  ".image-audit.:${inventory:-}"; do
  image_cleanup_track_temporary "${image_cleanup_temporary_spec#*:}" "${image_cleanup_temporary_spec%%:*}" || continue
done
image_cleanup_exit() {
  local status=$? path identity
  trap - EXIT HUP INT TERM
  if [[ "$image_cleanup_monitor_started" == true && "$image_cleanup_done" != true ]]; then
    retention_continuity_hold "executor_exit_$status" || true
    ((status != 0)) || status=1
  fi
  # The streamed audit's EXIT trap is replaced by this one. Remove only exact
  # mktemp files with the inode captured at admission/creation, never state,
  # plans, directories, caller-supplied reviews or subsequently replaced paths.
  for path in "${image_cleanup_audit_temporaries[@]}"; do
    [[ -f "$path" && ! -L "$path" ]] || continue
    identity=$(stat -Lc '%d:%i' -- "$path") || continue
    [[ "$identity" == "${image_cleanup_audit_temporary_ids[$path]}" ]] || continue
    rm -f -- "$path"
  done
  exit "$status"
}
trap image_cleanup_exit EXIT
trap 'echo "IMAGE_CLEANUP_INTERRUPTED=HUP" >&2; exit 129' HUP
trap 'echo "IMAGE_CLEANUP_INTERRUPTED=INT" >&2; exit 130' INT
trap 'echo "IMAGE_CLEANUP_INTERRUPTED=TERM" >&2; exit 143' TERM

if [[ -z "$review_file" ]]; then
  review_file=$(mktemp "$image_cleanup_root/.image-review.XXXXXX")
  image_cleanup_track_temporary "$review_file" .image-review. || image_cleanup_fail unsafe_temporary_review
  image_cleanup_review_temporary=true
  chmod 600 "$review_file"
  retention_images_review_load "$review_file" || image_cleanup_fail review_load_failed
fi
for image_cleanup_input in "$review_file" "${retention_snapshot_file:-}" "${retention_startup_file:-}" "${active_state_file:-}"; do
  [[ "$image_cleanup_input" == /* && -f "$image_cleanup_input" && ! -L "$image_cleanup_input" &&
     "$(realpath -e -- "$image_cleanup_input")" == "$image_cleanup_input" ]] || image_cleanup_fail invalid_audit_input
done
[[ "$active_state_file" == "$image_cleanup_root/$EXPECTED_ACTIVE_RELEASE_ID/state.json" ]] || image_cleanup_fail active_state_path_mismatch
[[ "$(retention_hash < "$review_file")" == "$EXPECTED_RETENTION_REVIEW_SHA256" ]] || image_cleanup_fail review_hash_mismatch
retention_images_review_validate "$review_file" || image_cleanup_fail review_not_approved
retention_images_review_fresh "$review_file" || image_cleanup_fail approval_expired
jq -e '(.schema_version==2 or .schema_version==3) and .status=="approved" and .operation=="image_cleanup"' "$review_file" >/dev/null || image_cleanup_fail image_only_review_required
jq -e '.containers==[]' "$review_file" >/dev/null || image_cleanup_fail containers_forbidden
jq -e '.images|type=="array" and length==1' "$review_file" >/dev/null || image_cleanup_fail exactly_one_image_required
jq -e --arg id "$EXPECTED_ACTIVE_RELEASE_ID" --arg sha "$EXPECTED_ACTIVE_RELEASE_SHA" \
  --arg fingerprint "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" \
  '.active_release_id==$id and .active_release_sha==$sha and .resource_fingerprint==$fingerprint' \
  "$review_file" >/dev/null || image_cleanup_fail review_identity_mismatch

image_cleanup_id=$(jq -er '.images[0].id' "$review_file")
[[ "$image_cleanup_id" =~ ^sha256:[a-f0-9]{64}$ ]] || image_cleanup_fail invalid_image_id
image_cleanup_plan="$image_cleanup_root/image-cleanup-plan-$EXPECTED_RETENTION_REVIEW_SHA256.json"
[[ ! -e "$image_cleanup_plan" && ! -L "$image_cleanup_plan" ]] || image_cleanup_fail prior_execution_requires_review
image_cleanup_original_state_hash=$(retention_hash < "$active_state_file")
image_cleanup_startup_hash=$(retention_hash < "$retention_startup_file")
[[ "$image_cleanup_startup_hash" == "$(jq -er '.images[0].evidence.startup_reference_review_sha256' "$review_file")" ]] || image_cleanup_fail startup_review_mismatch
image_cleanup_protection_hash=$(jq -er '.protection_fingerprint' "$review_file")
image_cleanup_selected=$(jq -ceS --arg id "$image_cleanup_id" '.images[]|select(.id==$id)' "$retention_snapshot_file") || image_cleanup_fail reviewed_image_missing
[[ "$(printf '%s\n' "$image_cleanup_selected" | retention_hash)" == "$(jq -er '.images[0].snapshot_sha256' "$review_file")" ]] || image_cleanup_fail image_snapshot_mismatch
if [[ "$(jq -er .schema_version "$review_file")" == 3 ]]; then
  # Restore identity is immutable. Never pull on production; the runner verifies
  # the registry image before host access, and this binds it to the live audit.
  jq -e --slurpfile review "$review_file" '
    .revision==$review[0].images[0].revision and
    (.digests|index($review[0].images[0].restore_digest)!=null)' \
    <<< "$image_cleanup_selected" >/dev/null || image_cleanup_fail restore_identity_not_in_audit
  # Include stopped/rollback/anchor containers, not merely the six active roles.
  # A shortened monitor list must not make preservation of omitted roles pass.
  jq -e --slurpfile review "$review_file" '
    [.containers[]|{id,status,started_at,restart_count}]|sort_by(.id) ==
      ($review[0].monitoring.protected_containers|sort_by(.id))' \
    "$retention_snapshot_file" >/dev/null || image_cleanup_fail monitoring_inventory_mismatch
fi
jq -e --slurpfile review "$review_file" '.size_bytes>0 and .size_bytes<=$review[0].max_image_size_bytes' \
  <<< "$image_cleanup_selected" >/dev/null || image_cleanup_fail image_size_exceeds_review
retention_image_eligible "$image_cleanup_selected" "$review_file" "$(date -u +%s)" "$RETENTION_IMAGE_MIN_AGE_SECONDS" || image_cleanup_fail image_not_eligible
jq -e --slurpfile review "$review_file" '
  [.digests[] as $candidate | $review[0].protected_image_digests[] |
    select((split("@")|last)==($candidate|split("@")|last))]|length==0
' <<< "$image_cleanup_selected" >/dev/null || image_cleanup_fail protected_manifest_digest
jq -e '(.tags+.digests|unique|length)>0 and (.tags+.digests|unique|length)<=8' \
  <<< "$image_cleanup_selected" >/dev/null || image_cleanup_fail invalid_alias_count
jq -e --arg id "$image_cleanup_id" 'all(.containers[];.image_id!=$id)' \
  "$retention_snapshot_file" >/dev/null || image_cleanup_fail image_is_container_referenced
jq -e --argjson selected "$image_cleanup_selected" '
  .schema_version==1 and
  (.image_ids|index($selected.id)==null) and
  (.revisions|index($selected.revision)==null) and
  # A manifest digest identifies the same content across registry/repository
  # aliases. A different prefix must never hide a protected startup reference.
  ([.digests[] as $ref | $selected.digests[] |
    select((split("@")|last)==($ref|split("@")|last))]|length==0) and
  ([.references[] as $ref | ([$selected.id]+$selected.tags+$selected.digests) | index($ref) | select(.!=null)]|length==0)
' "$retention_startup_file" >/dev/null || image_cleanup_fail image_has_startup_reference

image_cleanup_current=$(mktemp "$image_cleanup_root/.image-cleanup-current.XXXXXX")
image_cleanup_track_temporary "$image_cleanup_current" .image-cleanup-current. || image_cleanup_fail unsafe_temporary_inventory
image_cleanup_temporary=$(mktemp "$image_cleanup_root/.image-cleanup-plan.XXXXXX")
image_cleanup_track_temporary "$image_cleanup_temporary" .image-cleanup-plan. || image_cleanup_fail unsafe_temporary_plan
chmod 600 "$image_cleanup_current" "$image_cleanup_temporary"

image_cleanup_check_fixed() {
  local protection startup
  image_cleanup_assert_lock || return 1
  if [[ -n "$image_cleanup_plan_hash" ]]; then
    [[ -f "$image_cleanup_plan" && ! -L "$image_cleanup_plan" &&
       "$(retention_hash < "$image_cleanup_plan")" == "$image_cleanup_plan_hash" ]] || {
      image_cleanup_fail immutable_plan_changed; return 1;
    }
  fi
  [[ "$(retention_hash < "$review_file")" == "$EXPECTED_RETENTION_REVIEW_SHA256" ]] || { image_cleanup_fail review_changed; return 1; }
  [[ "$(retention_hash < "$active_state_file")" == "$image_cleanup_original_state_hash" ]] || { image_cleanup_fail active_state_changed; return 1; }
  protection=$(retention_live_protection) || { image_cleanup_fail live_protection_invalid; return 1; }
  [[ "$(printf '%s\n' "$protection" | retention_hash)" == "$image_cleanup_protection_hash" ]] || { image_cleanup_fail protected_resources_changed; return 1; }
  startup=$(retention_startup_references) || { image_cleanup_fail startup_inspection_failed; return 1; }
  [[ "$(printf '%s\n' "$startup" | retention_hash)" == "$image_cleanup_startup_hash" ]] || { image_cleanup_fail startup_references_changed; return 1; }
}
image_cleanup_check_containers() {
  local ids id snapshots='' snapshot current
  ids=$(docker ps -aq --no-trunc) || { image_cleanup_fail container_inventory_failed; return 1; }
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    snapshot=$(retention_container_snapshot "$id") || { image_cleanup_fail container_snapshot_failed; return 1; }
    snapshots+="$snapshot"$'\n'
  done <<< "$ids"
  current=$(jq -csS 'sort_by(.id)' <<< "$snapshots") || return 1
  jq -e --argjson current "$current" --arg id "$image_cleanup_id" \
    '.inventory.containers==$current and all($current[];.image_id!=$id)' "$image_cleanup_plan" >/dev/null || {
    image_cleanup_fail container_inventory_changed; return 1;
  }
}
image_cleanup_check_target() {
  local ids snapshot
  ids=$(docker image ls -aq --no-trunc) || { image_cleanup_fail image_inventory_failed; return 1; }
  if ! grep -Fxq "$image_cleanup_id" <<< "$ids"; then
    [[ "$image_cleanup_intent_recorded" == true ]] || { image_cleanup_fail unexplained_missing_image; return 1; }
    image_cleanup_target_present=false
    image_cleanup_target=''
    return 0
  fi
  snapshot=$(retention_image_snapshot "$image_cleanup_id") || { image_cleanup_fail image_snapshot_failed; return 1; }
  jq -e --argjson original "$image_cleanup_selected" \
    'del(.tags,.digests)==($original|del(.tags,.digests)) and
      ((.tags-$original.tags)|length==0) and ((.digests-$original.digests)|length==0)' \
    <<< "$snapshot" >/dev/null || { image_cleanup_fail image_identity_or_alias_changed; return 1; }
  if [[ "$image_cleanup_intent_recorded" != true && "$snapshot" != "$image_cleanup_selected" ]]; then
    image_cleanup_fail unexplained_missing_alias; return 1
  fi
  image_cleanup_target_present=true
  image_cleanup_target=$snapshot
}
image_cleanup_check_inventory() {
  retention_all_snapshots > "$image_cleanup_current" || { image_cleanup_fail inventory_read_failed; return 1; }
  if [[ "$image_cleanup_intent_recorded" == false ]]; then
    if [[ -n "$image_cleanup_plan_hash" ]]; then
      jq -e --slurpfile original "$image_cleanup_plan" '.==$original[0].inventory' \
        "$image_cleanup_current" >/dev/null || { image_cleanup_fail inventory_changed_before_cleanup; return 1; }
    else
      jq -e --slurpfile original "$retention_snapshot_file" '.==$original[0]' \
        "$image_cleanup_current" >/dev/null || { image_cleanup_fail inventory_changed_before_cleanup; return 1; }
    fi
  else
    retention_inventory_within_plan "$image_cleanup_plan" "$image_cleanup_current" || {
      image_cleanup_fail inventory_outside_reviewed_plan; return 1;
    }
  fi
}

image_cleanup_check_fixed
image_cleanup_check_inventory
jq -cnS --slurpfile inventory "$retention_snapshot_file" --slurpfile startup "$retention_startup_file" \
  --argjson image "$image_cleanup_selected" --arg review "$EXPECTED_RETENTION_REVIEW_SHA256" \
  --arg workflow "$EXPECTED_WORKFLOW_SHA" --arg state "$image_cleanup_original_state_hash" \
  '{schema_version:2,operation:"image_cleanup",review_sha256:$review,workflow_sha:$workflow,
    active_state_sha256:$state,inventory:$inventory[0],startup:$startup[0],containers:[],images:[$image]}' > "$image_cleanup_temporary"
# An atomic link is no-clobber. A failed or interrupted run leaves its immutable
# plan as evidence and must not turn a retry into deletion authority.
ln -- "$image_cleanup_temporary" "$image_cleanup_plan" || image_cleanup_fail prior_execution_requires_review
image_cleanup_plan_hash=$(retention_hash < "$image_cleanup_plan")
image_cleanup_monitor_started=true
retention_continuity_begin "$review_file" || image_cleanup_fail continuity_baseline_failed
image_cleanup_check_fixed
image_cleanup_check_inventory
retention_images_review_fresh "$review_file" || image_cleanup_fail approval_expired
retention_continuity_check || image_cleanup_fail continuity_check_failed
retention_continuity_intent "$image_cleanup_id" || image_cleanup_fail continuity_intent_failed
image_cleanup_intent_recorded=true

while IFS= read -r image_cleanup_reference; do
  retention_images_review_fresh "$review_file" || image_cleanup_fail approval_expired
  retention_continuity_check || image_cleanup_fail continuity_check_failed
  image_cleanup_check_fixed
  image_cleanup_check_containers
  image_cleanup_check_target
  if [[ "$image_cleanup_target_present" == true ]] &&
     jq -e --arg reference "$image_cleanup_reference" '(.tags+.digests)|index($reference)!=null' \
       <<< "$image_cleanup_target" >/dev/null; then
    image_cleanup_resolved=$(docker image inspect -f '{{.Id}}' "$image_cleanup_reference") || image_cleanup_fail alias_inspection_failed
    [[ "$image_cleanup_resolved" == "$image_cleanup_id" ]] || image_cleanup_fail alias_retargeted
    printf 'IMAGE_CLEANUP_ALIAS_INTENT id=%s reference=%s\n' "$image_cleanup_id" "$image_cleanup_reference"
    timeout --signal=TERM --kill-after=10s 90s docker image rm --no-prune "$image_cleanup_reference" >/dev/null || image_cleanup_fail image_delete_failed
  else
    echo "IMAGE_CLEANUP_ALIAS id=$image_cleanup_id result=already_absent_after_intent"
  fi
done < <(jq -r '(.tags+.digests)|unique[]' <<< "$image_cleanup_selected")
retention_continuity_check || image_cleanup_fail continuity_check_failed
image_cleanup_check_fixed
image_cleanup_check_containers
image_cleanup_check_target
if [[ "$image_cleanup_target_present" == true ]]; then
  jq -e '(.tags+.digests|length)==0' <<< "$image_cleanup_target" >/dev/null || image_cleanup_fail remaining_aliases
  retention_images_review_fresh "$review_file" || image_cleanup_fail approval_expired
  timeout --signal=TERM --kill-after=10s 90s docker image rm --no-prune "$image_cleanup_id" >/dev/null || image_cleanup_fail image_delete_failed
fi
image_cleanup_check_fixed
image_cleanup_check_inventory
jq -e --arg id "$image_cleanup_id" 'all(.images[];.id!=$id)' "$image_cleanup_current" >/dev/null || image_cleanup_fail image_still_present
retention_continuity_observe "$image_cleanup_id" || image_cleanup_fail continuity_observation_failed
image_cleanup_check_fixed
image_cleanup_check_inventory
retention_continuity_check || image_cleanup_fail continuity_check_failed
retention_continuity_complete || image_cleanup_fail continuity_completion_failed
image_cleanup_done=true
echo "IMAGE_CLEANUP=PASS result=complete id=$image_cleanup_id images=1 containers=preserved volumes=preserved directories=preserved lifecycle=unchanged review_sha256=$EXPECTED_RETENTION_REVIEW_SHA256"
