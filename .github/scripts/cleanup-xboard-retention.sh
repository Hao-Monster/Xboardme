#!/usr/bin/env bash
set -Eeuo pipefail

# Stream after discovery, release-state, retention-safety and the locked audit.
# A workflow-reviewed exact list is mandatory. This executor never adopts
# candidates from a later Docker listing, including after a partial failure.
: "${EXPECTED_RETENTION_RESOURCE_FINGERPRINT:?required}"
: "${EXPECTED_ACTIVE_RELEASE_ID:?required}"
: "${EXPECTED_ACTIVE_RELEASE_SHA:?required}"
: "${RETENTION_IMAGE_MIN_AGE_SECONDS:=604800}"
: "${RETENTION_MAX_OBJECTS_PER_RUN:=1}"
: "${RETENTION_OBSERVE_SECONDS:=60}"

cleanup_fail() { echo "RETENTION_CLEANUP_FAIL=$1" >&2; return 1; }

[[ "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" =~ ^[a-f0-9]{64}$ ]] || cleanup_fail invalid_resource_fingerprint
[[ "$EXPECTED_ACTIVE_RELEASE_ID" =~ ^[0-9]+-[0-9]+$ ]] || cleanup_fail invalid_release_id
[[ "$EXPECTED_ACTIVE_RELEASE_SHA" =~ ^[a-f0-9]{40}$ ]] || cleanup_fail invalid_release_sha
[[ "$RETENTION_IMAGE_MIN_AGE_SECONDS" =~ ^[0-9]+$ ]] || cleanup_fail invalid_image_min_age
((RETENTION_IMAGE_MIN_AGE_SECONDS >= 604800)) || cleanup_fail image_min_age_below_seven_days
[[ "$RETENTION_MAX_OBJECTS_PER_RUN" =~ ^[1-5]$ ]] || cleanup_fail invalid_batch_limit
[[ "$RETENTION_OBSERVE_SECONDS" =~ ^[0-9]+$ ]] || cleanup_fail invalid_observation_seconds
((RETENTION_OBSERVE_SECONDS >= 60 && RETENTION_OBSERVE_SECONDS <= 300)) || cleanup_fail invalid_observation_seconds
[[ "${RETENTION_REQUIRE_FINALIZED:-false}" == true && "${RETENTION_ACQUIRE_LOCK:-false}" == true ]] || cleanup_fail locked_finalized_audit_required
[[ "${active_release_id:-}" == "$EXPECTED_ACTIVE_RELEASE_ID" && "${active_revision:-}" == "$EXPECTED_ACTIVE_RELEASE_SHA" &&
   "${active_project:-}" == "xboard-v2-$EXPECTED_ACTIVE_RELEASE_ID" ]] || cleanup_fail active_identity_mismatch
[[ "${active_traffic_state:-}" == finalized && "${active_rollback_supported:-}" == false &&
   -z "${direct_previous_project:-}" ]] || cleanup_fail rollback_support_not_closed
[[ -f "${active_state_file:-}" && ! -L "$active_state_file" && -f "${retention_snapshot_file:-}" ]] || cleanup_fail audit_context_missing
release_assert_cleanup_admission "$workdir"
for tool in base64 date docker jq mktemp sha256sum sleep sort timeout; do
  command -v "$tool" >/dev/null || cleanup_fail "missing_tool:$tool"
done
declare -F release_state_set_many >/dev/null || cleanup_fail atomic_state_helper_missing

cleanup_dir=$(dirname -- "$active_state_file")
[[ "$(realpath -e -- "$cleanup_dir")" == "$workdir/.codex-v2-release/$EXPECTED_ACTIVE_RELEASE_ID" ]] || cleanup_fail unsafe_plan_directory
plan_file="$cleanup_dir/retention-cleanup-plan.json"
progress_file="$cleanup_dir/retention-cleanup-progress.json"
review_file=$(mktemp "$cleanup_dir/retention-review.XXXXXX")
current_file=$(mktemp "$cleanup_dir/retention-current.XXXXXX")
chmod 600 "$review_file" "$current_file"
cleanup_started=false

cleanup_finish() {
  local status=$?
  trap - EXIT ERR HUP INT TERM
  if [[ "$cleanup_started" == true && "$status" != 0 ]]; then
    release_state_set_many "$active_state_file" retention_cleanup_status failed \
      retention_cleanup_failed_at "$(date -u +%FT%TZ)" || true
    # This is only a read-only identity check. Workflow post-verification also
    # runs after failure; public/DB acceptance is not inferred from this result.
    if live=$(retention_live_protection) &&
       [[ "$(printf '%s\n' "$live" | retention_hash)" == "$baseline_protection_hash" ]]; then
      echo 'RETENTION_FAILURE_PROTECTION=PASS'
    else
      echo 'RETENTION_FAILURE_PROTECTION=FAIL' >&2
    fi
    [[ ! -f "$progress_file" ]] || jq -c '{plan_sha256,attempts,completed}' "$progress_file"
  fi
  rm -f -- "$review_file" "$current_file" "${inventory:-}" "${retention_snapshot_file:-}"
  exit "$status"
}
trap cleanup_finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

retention_load_review "$review_file" retention_cleanup || cleanup_fail reviewed_manifest_required
baseline_protection_hash=$(jq -r .protection_fingerprint "$review_file")
live=$(retention_live_protection) || cleanup_fail live_protection_invalid
[[ "$(printf '%s\n' "$live" | retention_hash)" == "$baseline_protection_hash" ]] || cleanup_fail live_protection_changed

recorded_plan_hash=$(release_state_get_optional "$active_state_file" retention_cleanup_plan_sha256)
recorded_status=$(release_state_get_optional "$active_state_file" retention_cleanup_status)
case "$recorded_status" in ''|running|failed|pending_batch|complete) ;; *) cleanup_fail invalid_cleanup_retry_status ;; esac

if [[ -z "$recorded_plan_hash" ]]; then
  # Old v1 attempts lack an exact allowlist and cannot be resumed safely.
  [[ -z "$(release_state_get_optional "$active_state_file" retention_cleanup_source_fingerprint)" ]] || cleanup_fail legacy_retry_plan_missing
  [[ "${resource_fingerprint:-}" == "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" ]] || cleanup_fail resource_fingerprint_mismatch
  [[ ! -e "$plan_file" && ! -e "$progress_file" && ! -L "$plan_file" && ! -L "$progress_file" ]] || cleanup_fail unbound_cleanup_evidence
  retention_verify_reviewed_containers "$review_file" "$(jq -r '.containers[].id' "$review_file")" || cleanup_fail container_review_mismatch
  containers=$(jq -c --slurpfile review "$review_file" '[.containers[] | . as $c | select(any($review[0].containers[];.id==$c.id))]' "$retention_snapshot_file")
  images=$(jq -c --slurpfile review "$review_file" '[.images[] | . as $i | select(any($review[0].images[];.id==$i.id))]' "$retention_snapshot_file")
  [[ $(jq length <<< "$containers") == $(jq '.containers|length' "$review_file") &&
     $(jq length <<< "$images") == $(jq '.images|length' "$review_file") ]] || cleanup_fail reviewed_object_missing
  now=$(date -u +%s)
  while IFS= read -r selected; do
    id=$(jq -r .id <<< "$selected")
    expected_hash=$(jq -r --arg id "$id" '.images[]|select(.id==$id)|.snapshot_sha256' "$review_file")
    [[ "$(printf '%s\n' "$selected" | retention_hash)" == "$expected_hash" ]] || cleanup_fail "image_review_mismatch:$id"
    retention_image_eligible "$selected" "$review_file" "$now" "$RETENTION_IMAGE_MIN_AGE_SECONDS" || cleanup_fail "image_not_eligible:$id"
    jq -e --arg id "$id" 'all(.containers[];.image_id!=$id)' "$retention_snapshot_file" >/dev/null || cleanup_fail "reviewed_image_referenced:$id"
  done < <(jq -cS '.[]' <<< "$images")
  jq -e --argjson protection "$live" 'all(.[];.id as $id | all($protection.containers[];.id!=$id))' \
    <<< "$containers" >/dev/null || cleanup_fail protected_container_in_plan
  temporary=$(mktemp "$cleanup_dir/retention-plan.XXXXXX")
  jq -cnS --slurpfile review "$review_file" --slurpfile inventory_snapshot "$retention_snapshot_file" \
    --arg manifest_sha "$EXPECTED_RETENTION_REVIEW_SHA256" --arg workflow_sha "$EXPECTED_WORKFLOW_SHA" \
    --argjson protection "$live" --argjson containers "$containers" --argjson images "$images" \
    '{schema_version:1,manifest_sha256:$manifest_sha,workflow_sha:$workflow_sha,review:$review[0],
      inventory:$inventory_snapshot[0],protection:$protection,containers:$containers,images:$images}' > "$temporary"
  chmod 600 "$temporary"
  mv -- "$temporary" "$plan_file"
  recorded_plan_hash=$(retention_hash < "$plan_file")
  temporary=$(mktemp "$cleanup_dir/retention-progress.XXXXXX")
  jq -cnS --arg hash "$recorded_plan_hash" '{schema_version:1,plan_sha256:$hash,attempts:[],completed:[]}' > "$temporary"
  chmod 600 "$temporary"
  mv -- "$temporary" "$progress_file"
  release_state_set_many "$active_state_file" retention_cleanup_plan_sha256 "$recorded_plan_hash" \
    retention_cleanup_source_fingerprint "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" \
    retention_cleanup_review_sha256 "$EXPECTED_RETENTION_REVIEW_SHA256" retention_cleanup_workflow_sha "$EXPECTED_WORKFLOW_SHA" \
    retention_cleanup_status running
else
  [[ -f "$plan_file" && ! -L "$plan_file" && -f "$progress_file" && ! -L "$progress_file" ]] || cleanup_fail retry_plan_missing
  [[ "$(retention_hash < "$plan_file")" == "$recorded_plan_hash" ]] || cleanup_fail retry_plan_integrity_mismatch
  jq -e --arg manifest "$EXPECTED_RETENTION_REVIEW_SHA256" --arg fingerprint "$EXPECTED_RETENTION_RESOURCE_FINGERPRINT" \
    --arg workflow "$EXPECTED_WORKFLOW_SHA" '.manifest_sha256==$manifest and .workflow_sha==$workflow and
      .review.resource_fingerprint==$fingerprint' "$plan_file" >/dev/null || cleanup_fail retry_approval_changed
fi
jq -e --arg hash "$recorded_plan_hash" --slurpfile plan "$plan_file" '
  . as $progress | .schema_version==1 and .plan_sha256==$hash and
  (.attempts|type=="array") and (.completed|type=="array") and
  (.attempts|length== (unique_by(.kind,.id)|length)) and
  (.completed|length== (unique_by(.kind,.id)|length)) and
  all(.attempts[]; . as $entry |
    ((.kind=="container" and any($plan[0].containers[];.id==$entry.id)) or
     (.kind=="image" and any($plan[0].images[];.id==$entry.id)))) and
  all(.completed[]; . as $entry | any($progress.attempts[];.==$entry))
' "$progress_file" >/dev/null || cleanup_fail invalid_progress_ledger

cleanup_check() {
  local actual entry candidate_id project history_file
  actual=$(retention_live_protection) || cleanup_fail live_protection_invalid
  [[ "$(printf '%s\n' "$actual" | retention_hash)" == "$baseline_protection_hash" ]] || cleanup_fail live_protection_changed
  retention_all_snapshots > "$current_file" || cleanup_fail live_inventory_failed
  retention_inventory_within_plan "$plan_file" "$current_file" || cleanup_fail inventory_outside_frozen_plan
  # Missing/untagged objects are idempotent only after a durable deletion intent.
  jq -e --slurpfile live "$current_file" --slurpfile progress "$progress_file" '
    all(.containers[]; . as $old |
      any($live[0].containers[];.id==$old.id) or any($progress[0].attempts[];.kind=="container" and .id==$old.id)) and
    all(.images[]; . as $old |
      any($live[0].images[];.==$old) or any($progress[0].attempts[];.kind=="image" and .id==$old.id))
  ' "$plan_file" >/dev/null || cleanup_fail unaccounted_resource_disappearance
  jq -e --slurpfile live "$current_file" 'all(.completed[]; . as $entry |
    if .kind=="container" then all($live[0].containers[];.id!=$entry.id)
    else all($live[0].images[];.id!=$entry.id) end)' "$progress_file" >/dev/null || cleanup_fail completed_object_present
  # A later run must not reuse an old manual history review after the relevant
  # release state has changed, even if Docker labels happen to stay identical.
  while IFS= read -r entry; do
    candidate_id=$(jq -r .id <<< "$entry")
    project=$(jq -r --arg id "$candidate_id" '.containers[]|select(.id==$id)|.project' "$current_file")
    [[ -n "$project" ]] || continue
    [[ "$project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]] || cleanup_fail candidate_history_project_invalid
    history_file="$workdir/.codex-v2-release/${BASH_REMATCH[1]}/state.json"
    [[ -f "$history_file" && ! -L "$history_file" && "$(realpath -e -- "$history_file")" == "$history_file" &&
       "$(retention_hash < "$history_file")" == "$(jq -r .evidence.release_state_sha256 <<< "$entry")" ]] || cleanup_fail candidate_history_changed
  done < <(jq -c '.review.containers[]' "$plan_file")
}

cleanup_progress() {
  local field=$1 kind=$2 id=$3 temporary
  temporary=$(mktemp "$cleanup_dir/retention-progress.XXXXXX")
  if ! jq -cS --arg field "$field" --arg kind "$kind" --arg id "$id" \
    '.[$field] = ((.[$field]+[{kind:$kind,id:$id}])|unique_by(.kind,.id))' "$progress_file" > "$temporary"; then
    rm -f -- "$temporary"
    return 1
  fi
  chmod 600 "$temporary"
  mv -f -- "$temporary" "$progress_file"
}

cleanup_check
cleanup_started=true
release_state_set_many "$active_state_file" retention_cleanup_status running retention_cleanup_started_at "$(date -u +%FT%TZ)"
processed=0
processed_containers=0
limit=$RETENTION_MAX_OBJECTS_PER_RUN
# First batch is a single canary, regardless of a larger requested ceiling.
if [[ $(jq '.attempts|length' "$progress_file") == 0 ]]; then limit=1; fi

while IFS= read -r selected; do
  ((processed < limit && processed_containers < 2)) || break
  id=$(jq -r .id <<< "$selected")
  jq -e --arg id "$id" 'any(.completed[];.kind=="container" and .id==$id)' "$progress_file" >/dev/null && continue
  cleanup_check
  if jq -e --arg id "$id" 'any(.containers[];.id==$id)' "$current_file" >/dev/null; then
    cleanup_progress attempts container "$id"
    timeout --signal=TERM --kill-after=10s 90s docker container rm "$id" >/dev/null
    cleanup_check
    jq -e --arg id "$id" 'all(.containers[];.id!=$id)' "$current_file" >/dev/null || cleanup_fail container_still_present
    echo "RETENTION_CLEANUP_CONTAINER id=$id result=removed force=false volume_removal=false"
  else
    echo "RETENTION_CLEANUP_CONTAINER id=$id result=absent_after_recorded_intent"
  fi
  cleanup_progress completed container "$id"
  ((processed+=1,processed_containers+=1))
done < <(jq -cS '.containers[]' "$plan_file")

while IFS= read -r selected; do
  ((processed < limit)) || break
  id=$(jq -r .id <<< "$selected")
  jq -e --arg id "$id" 'any(.completed[];.kind=="image" and .id==$id)' "$progress_file" >/dev/null && continue
  cleanup_check
  cleanup_progress attempts image "$id"
  # Aliases can disappear together. Inspect before EVERY reference and reject
  # changed targets/new aliases. Names never confer deletion authority.
  while IFS= read -r reference; do
    cleanup_check
    jq -e --arg id "$id" 'all(.containers[];.image_id!=$id)' "$current_file" >/dev/null || cleanup_fail image_gained_container_reference
    if jq -e --arg id "$id" --arg reference "$reference" \
      'any(.images[];.id==$id and ((.tags+.digests)|index($reference)!=null))' "$current_file" >/dev/null; then
      resolved=$(docker image inspect -f '{{.Id}}' "$reference") || cleanup_fail image_reference_inspect_failed
      [[ "$resolved" == "$id" ]] || cleanup_fail image_reference_retargeted
      timeout --signal=TERM --kill-after=10s 90s docker image rm --no-prune "$reference" >/dev/null
    else
      jq -e --arg reference "$reference" 'all(.images[];((.tags+.digests)|index($reference)==null))' \
        "$current_file" >/dev/null || cleanup_fail image_reference_retargeted
      echo "RETENTION_CLEANUP_IMAGE_REFERENCE id=$id result=already_absent"
    fi
  done < <(jq -r '(.tags+.digests)|unique[]' <<< "$selected")
  cleanup_check
  if jq -e --arg id "$id" 'any(.images[];.id==$id)' "$current_file" >/dev/null; then
    jq -e --arg id "$id" 'all(.containers[];.image_id!=$id) and all(.images[]|select(.id==$id);(.tags+.digests|length)==0)' \
      "$current_file" >/dev/null || cleanup_fail image_not_unreferenced
    timeout --signal=TERM --kill-after=10s 90s docker image rm --no-prune "$id" >/dev/null
  fi
  cleanup_check
  jq -e --arg id "$id" 'all(.images[];.id!=$id)' "$current_file" >/dev/null || cleanup_fail image_still_present
  cleanup_progress completed image "$id"
  ((processed+=1))
  echo "RETENTION_CLEANUP_IMAGE id=$id result=removed force=false"
done < <(jq -cS '.images[]' "$plan_file")

# No unattended 135-image loop: a canary or <=5 reviewed objects, <=2 stopped
# containers, then mandatory observation. Normal login/DB smoke is separate.
for ((observed=0;observed<RETENTION_OBSERVE_SECONDS;observed+=10)); do
  sleep 10
  cleanup_check
done
remaining=$(jq --slurpfile progress "$progress_file" \
  '((.containers|length)+(.images|length))-($progress[0].completed|length)' "$plan_file")
status=pending_batch
((remaining != 0)) || status=complete
release_state_set_many "$active_state_file" retention_cleanup_status "$status" \
  retention_cleanup_last_batch_at "$(date -u +%FT%TZ)" retention_cleanup_remaining "$remaining"
cleanup_started=false
echo "RETENTION_CLEANUP=PASS result=$status id=$EXPECTED_ACTIVE_RELEASE_ID processed=$processed remaining=$remaining plan_sha256=$recorded_plan_hash volumes=preserved directories=preserved"
