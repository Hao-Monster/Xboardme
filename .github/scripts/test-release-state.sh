#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=release-state.sh
source "$script_dir/release-state.sh"

test_root=$(mktemp -d)
cleanup() { rm -rf -- "$test_root"; }
trap cleanup EXIT

state_file="$test_root/state.json"
execution_marker="$test_root/executed"
malicious_value='$(touch '"$execution_marker"')'

release_state_create "$state_file" \
  release_id '123-1' \
  traffic_state blue \
  untrusted_value "$malicious_value"
[[ "$(release_state_get "$state_file" release_id)" == '123-1' ]]
[[ "$(release_state_get "$state_file" untrusted_value)" == "$malicious_value" ]]
[[ "$(release_state_open "$test_root")" == "$state_file" ]]
[[ ! -e "$execution_marker" ]]

release_state_set "$state_file" traffic_state green
[[ "$(release_state_get "$state_file" traffic_state)" == green ]]
[[ "$(stat -c '%a' "$state_file")" == 600 ]]

# The lifecycle tuple must be replaced together, never one key at a time.
release_state_set_many "$state_file" traffic_state finalized rollback_supported false \
  finalized_at '2026-10-12T00:00:00Z' untrusted_value "$malicious_value"
[[ "$(release_state_get "$state_file" traffic_state)" == finalized ]]
[[ "$(release_state_get "$state_file" rollback_supported)" == false ]]
[[ "$(release_state_get "$state_file" untrusted_value)" == "$malicious_value" ]]
[[ ! -e "$execution_marker" ]]

release_state_set_many "$state_file" leading_option '--raw-output' leading_dash '-value'
[[ "$(release_state_get "$state_file" leading_option)" == '--raw-output' ]]
[[ "$(release_state_get "$state_file" leading_dash)" == '-value' ]]

assert_failed_update_preserves_state() {
  local failure=$1 before
  before=$(sha256sum "$state_file")
  case "$failure" in
    odd_pairs) ! release_state_set_many "$state_file" traffic_state green dangling >/dev/null 2>&1 ;;
    empty) ! release_state_set_many "$state_file" >/dev/null 2>&1 ;;
    duplicate) ! release_state_set_many "$state_file" traffic_state green traffic_state blue >/dev/null 2>&1 ;;
    bad_key) ! release_state_set_many "$state_file" traffic_state green 'bad-key' value >/dev/null 2>&1 ;;
    schema) ! release_state_set_many "$state_file" traffic_state green schema_version 1 >/dev/null 2>&1 ;;
    jq|chmod|mv)
      (
        # Faults are confined to this subshell; no filesystem or Docker daemon
        # outside the private test directory is touched.
        case "$failure" in
          jq) jq() { [[ "$*" != *--args* ]] && command jq "$@"; } ;;
          chmod) chmod() { return 72; } ;;
          mv) mv() { return 73; } ;;
        esac
        ! release_state_set_many "$state_file" traffic_state green rollback_supported true
      ) >/dev/null 2>&1
      ;;
  esac
  [[ "$(sha256sum "$state_file")" == "$before" ]]
  [[ $(find "$test_root" -maxdepth 1 -name 'state.json.*' -type f | wc -l) == 0 ]]
}
for failure in odd_pairs empty duplicate bad_key schema jq chmod mv; do
  assert_failed_update_preserves_state "$failure"
done

# Inspect the only rename boundary: readers see the old tuple until one
# complete, validated 0600 replacement is ready.
(
  mv() {
    [[ "$(release_state_get "$state_file" traffic_state)" == finalized ]]
    [[ "$(release_state_get "$state_file" rollback_supported)" == false ]]
    local temporary=${@: -2:1}
    [[ "$(stat -c '%a' "$temporary")" == 600 ]]
    [[ "$(release_state_get "$temporary" traffic_state)" == active_v2 ]]
    [[ "$(release_state_get "$temporary" rollback_supported)" == true ]]
    printf 'rename\n' >> "$test_root/renames.log"
    command mv "$@"
  }
  release_state_set_many "$state_file" traffic_state active_v2 rollback_supported true
)
[[ $(wc -l < "$test_root/renames.log") == 1 ]]
[[ "$(release_state_get "$state_file" traffic_state)" == active_v2 ]]
[[ "$(release_state_get "$state_file" rollback_supported)" == true ]]

legacy_file="$test_root/state.env"
legacy_json="$test_root/legacy.json"
printf 'RELEASE_ID=%q\n' '122-4' > "$legacy_file"
printf 'TRAFFIC_STATE=%q\n' blue >> "$legacy_file"
printf 'UNTRUSTED_VALUE=%q\n' "$malicious_value" >> "$legacy_file"
chmod 600 "$legacy_file"
release_state_import_legacy "$legacy_file" "$legacy_json"
[[ "$(release_state_get "$legacy_json" release_id)" == '122-4' ]]
[[ "$(release_state_get "$legacy_json" untrusted_value)" == "$malicious_value" ]]
[[ ! -e "$execution_marker" ]]

printf '%s\n' '{"schema_version":1,"release_id":"123-1"}' > "$test_root/invalid.json"
if release_state_validate "$test_root/invalid.json" >/dev/null 2>&1; then
  echo 'release state accepted an unsupported schema' >&2
  exit 1
fi

ln -s "$state_file" "$test_root/symlink.json"
if release_state_validate "$test_root/symlink.json" >/dev/null 2>&1; then
  echo 'release state accepted a symbolic link' >&2
  exit 1
fi

# Cleanup failures must survive workflow changes, even when the old caller did
# not include the new continuity helper. A missing gate retains compatibility.
admission_workdir="$test_root/admission-workdir"
mkdir -p "$admission_workdir/.codex-v2-release"
release_assert_cleanup_admission "$admission_workdir"
printf '{"status":"HOLD"}\n' > "$admission_workdir/.codex-v2-release/image-cleanup-gate.json"
if release_assert_cleanup_admission "$admission_workdir" > "$test_root/admission.log" 2>&1; then
  echo 'RELEASE_STATE_TEST_FAIL=missing_continuity_helper_was_accepted' >&2
  exit 1
fi
grep -q cleanup_admission_helper_missing "$test_root/admission.log"
retention_continuity_admit_deployment() {
  [[ "$1" == "$admission_workdir" ]]
  printf 'called\n' >> "$test_root/admission-calls"
  return 1
}
if release_assert_cleanup_admission "$admission_workdir" > "$test_root/admission.log" 2>&1; then
  echo 'RELEASE_STATE_TEST_FAIL=hold_was_ignored' >&2
  exit 1
fi
[[ $(wc -l < "$test_root/admission-calls") == 1 ]]
unset -f retention_continuity_admit_deployment
rm -- "$admission_workdir/.codex-v2-release/image-cleanup-gate.json"
ln -s "$test_root/not-existing-gate" "$admission_workdir/.codex-v2-release/image-cleanup-gate.json"
if release_assert_cleanup_admission "$admission_workdir" > "$test_root/admission.log" 2>&1; then
  echo 'RELEASE_STATE_TEST_FAIL=dangling_gate_was_ignored' >&2
  exit 1
fi

echo 'RELEASE_STATE_TEST=PASS cleanup_admission_cases=4'
