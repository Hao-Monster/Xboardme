#!/usr/bin/env bash
set -Eeuo pipefail
# Selection and predecessor admission use real helpers and secure gate files.
# Every review is synthetic; no repository approval or production host is read.
source_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/scripts" "$test_root/release/image-cleanup-reviews"
cd -- "$test_root"
cp -- "$source_dir/select-images-only-review.sh" "$test_root/scripts/"
source "$source_dir/retention-continuity.sh"
source "$test_root/scripts/select-images-only-review.sh"
default_review="$test_root/scripts/../release/image-cleanup-review.json"
first_review="$test_root/scripts/../release/image-cleanup-reviews/001.json"
second_review="$test_root/scripts/../release/image-cleanup-reviews/002.json"
printf '{}\n' > "$default_review"
printf '{}\n' > "$first_review"
printf '{}\n' > "$second_review"
cases=0
expect_success() {
  local name=$1
  shift
  if ! "$@" > "$test_root/result" 2>&1; then
    cat "$test_root/result" >&2; echo "IMAGE_SELECTION_TEST_FAIL=$name" >&2; exit 1
  fi
  ((cases+=1))
}
expect_failure() {
  local name=$1
  shift
  if "$@" > "$test_root/result" 2>&1; then
    echo "IMAGE_SELECTION_TEST_FAIL=unexpected_success:$name" >&2; exit 1
  fi
  ((cases+=1))
}
unset IMAGE_CLEANUP_REVIEW_ID
expect_success default_unset images_only_selected_review
[[ $(< "$test_root/result") == "$default_review" ]]
for identifier in default ''; do
  IMAGE_CLEANUP_REVIEW_ID=$identifier expect_success default_compatible images_only_selected_review
  [[ $(< "$test_root/result") == "$default_review" ]]
done
for identifier in 001 002; do
  IMAGE_CLEANUP_REVIEW_ID=$identifier expect_success fixed_identifier images_only_selected_review
  [[ $(< "$test_root/result") == "$test_root/scripts/../release/image-cleanup-reviews/$identifier.json" ]]
done
for identifier in 000 1 01 1000 -01 +01 1e2 DEFAULT '../001' '001.json' '/tmp/001' \
  '001/../002' '001;touch injected' '$(touch injected)' $'001\n002' $'001\t'; do
  IMAGE_CLEANUP_REVIEW_ID=$identifier expect_failure untrusted_identifier images_only_selected_review
  [[ ! -s "$test_root/result" ]]
done
IMAGE_CLEANUP_REVIEW_ID=003 expect_failure missing_selection images_only_selected_review
mv -- "$default_review" "$test_root/default.saved"
ln -s "$test_root/default.saved" "$default_review"
expect_failure default_symlink images_only_selected_review
rm -- "$default_review"
mv -- "$test_root/default.saved" "$default_review"
mv -- "$first_review" "$test_root/first.saved"
ln -s "$test_root/first.saved" "$first_review"
IMAGE_CLEANUP_REVIEW_ID=001 expect_failure selected_symlink images_only_selected_review
rm -- "$first_review"
mkdir "$first_review"
IMAGE_CLEANUP_REVIEW_ID=001 expect_failure selected_directory images_only_selected_review
rmdir -- "$first_review"
mv -- "$test_root/first.saved" "$first_review"
mv -- "$test_root/release/image-cleanup-reviews" "$test_root/sequence.saved"
ln -s "$test_root/sequence.saved" "$test_root/release/image-cleanup-reviews"
IMAGE_CLEANUP_REVIEW_ID=001 expect_failure sequence_directory_symlink images_only_selected_review
rm -- "$test_root/release/image-cleanup-reviews"
mv -- "$test_root/sequence.saved" "$test_root/release/image-cleanup-reviews"

# The original unsequenced schema remains valid, including no predecessor gate.
expect_success legacy_schema images_only_sequence_validate "$default_review"
expect_success legacy_predecessor images_only_sequence_predecessor "$default_review" "$test_root/missing"
jq -n '{schema_version:3,images:[{id:("sha256:"+("b"*64))}],
  sequence:{schema_version:1,index:"001",predecessor_review_sha256:null,audit_artifact_sha256:("a"*64)},
  recovery:{mode:"predelete_hold_v1"}}' > "$first_review"
expect_success first_schema images_only_sequence_validate "$first_review"
expect_success first_recovery_mode images_only_sequence_predecessor "$first_review" "$test_root/missing"
jq 'del(.recovery)' "$first_review" > "$test_root/invalid.json"
expect_failure first_requires_recovery images_only_sequence_predecessor "$test_root/invalid.json" "$test_root/missing"
jq '.recovery.mode="reset_any_hold"' "$first_review" > "$test_root/invalid.json"
expect_failure no_generic_reset images_only_sequence_predecessor "$test_root/invalid.json" "$test_root/missing"
first_hash=$(sha256sum < "$first_review" | awk '{print $1}')
jq --arg previous "$first_hash" '.images[0].id=("sha256:"+("c"*64)) |
  .sequence.index="002" | .sequence.predecessor_review_sha256=$previous | del(.recovery)' \
  "$first_review" > "$second_review"
expect_success next_schema images_only_sequence_validate "$second_review"
for filter in '.schema_version=2' '.sequence.schema_version=2' '.sequence.index="000"' \
  '.sequence.index="02"' '.sequence.index=2' '.sequence.index="../002"' \
  '.sequence.predecessor_review_sha256=null' '.sequence.predecessor_review_sha256=("0"*64)' \
  '.sequence.predecessor_review_sha256=("A"*64)' '.sequence.audit_artifact_sha256=("0"*64)' \
  '.sequence.audit_artifact_sha256=""' '.sequence.extra=true' 'del(.sequence.audit_artifact_sha256)'; do
  jq "$filter" "$second_review" > "$test_root/invalid.json"
  expect_failure invalid_sequence_schema images_only_sequence_validate "$test_root/invalid.json"
done
jq '.sequence.predecessor_review_sha256=("a"*64)' "$first_review" > "$test_root/invalid.json"
expect_failure first_cannot_claim_predecessor images_only_sequence_validate "$test_root/invalid.json"

gate="$test_root/gate.json"
jq -n --arg previous "$first_hash" --argjson owner "$EUID" '{
  schema_version:2,monitoring_mode:"passive_runtime_v1",status:"COMPLETE",owner_uid:$owner,
  session_id:"11111111-1111-1111-1111-111111111111",workflow_sha:("a"*40),
  active_release_sha:("a"*40),active_release_id:"123-1",review_sha256:$previous,
  protection_fingerprint:("d"*64),image_id:("sha256:"+("b"*64)),baseline_seconds:120,
  observe_seconds:300,sample_interval_seconds:10,started_at_uptime:1000,sample_count:43,
  cleanup_validated:true,failure_reason:null,baseline_started_at_uptime:1000,
  baseline_completed_at_uptime:1120,intent_at_uptime:1121,removed_at_uptime:1122,
  observation_completed_at_uptime:1422,completed_at_uptime:1423,last_heartbeat_uptime:1423,
  post_verification:"passed",post_evidence_sha256:("e"*64),post_verified_at_uptime:1424
}' > "$gate"
chmod 600 "$gate"
expect_success exact_predecessor images_only_sequence_predecessor "$second_review" "$gate"
original_gate_hash=$(sha256sum < "$gate" | awk '{print $1}')
for filter in '.status="RUNNING" | .post_verification="pending"' \
  '.status="HOLD" | .post_verification="failed" | .failure_reason="prior_failure"' \
  '.post_verification="pending"' '.cleanup_validated=false' '.review_sha256=("9"*64)' \
  '.image_id=("sha256:"+("c"*64))' '.post_evidence_sha256=null' \
  '.baseline_completed_at_uptime=1119' '.observation_completed_at_uptime=1421' \
  '.post_verified_at_uptime=1422' '.sample_count=0'; do
  jq "$filter" "$gate" > "$test_root/invalid-gate.json"
  chmod 600 "$test_root/invalid-gate.json"
  before=$(sha256sum < "$test_root/invalid-gate.json" | awk '{print $1}')
  expect_failure unsafe_predecessor images_only_sequence_predecessor "$second_review" "$test_root/invalid-gate.json"
  [[ $(sha256sum < "$test_root/invalid-gate.json" | awk '{print $1}') == "$before" ]]
done
expect_failure missing_predecessor images_only_sequence_predecessor "$second_review" "$test_root/missing"
ln -s "$gate" "$test_root/gate-link.json"
expect_failure predecessor_symlink images_only_sequence_predecessor "$second_review" "$test_root/gate-link.json"
chmod 644 "$gate"
expect_failure insecure_predecessor images_only_sequence_predecessor "$second_review" "$gate"
chmod 600 "$gate"
jq '.recovery={mode:"predelete_hold_v1"}' "$second_review" > "$test_root/invalid.json"
expect_failure later_cannot_recover images_only_sequence_predecessor "$test_root/invalid.json" "$gate"
jq '.sequence.predecessor_review_sha256=("8"*64)' "$second_review" > "$test_root/invalid.json"
expect_failure cannot_skip_predecessor images_only_sequence_predecessor "$test_root/invalid.json" "$gate"
[[ $(sha256sum < "$gate" | awk '{print $1}') == "$original_gate_hash" ]]
echo "IMAGE_SELECTION_TEST=PASS cases=$cases production_connections=0 gate_writes=0"
