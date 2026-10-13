#!/usr/bin/env bash
set -Eeuo pipefail
# Real filesystem/hash checks. Only Docker metadata is synthetic; no daemon is used.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/image-startup-references.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
cases=0
anchor_id=$(printf 'a%.0s' {1..64})
digest="ghcr.io/hao-monster/xboardme@sha256:$(printf 'b%.0s' {1..64})"
_ris_compose_metadata() { cat -- "$workdir/metadata.json"; }
fixture() {
  workdir="$test_root/case-$cases"
  mkdir -p "$workdir/.codex-v2-release/123-1"
  active_state_file="$workdir/.codex-v2-release/123-1/state.json"
  active_project=xboard-v2-123-1
  XBOARD_ANCHOR_CONTAINER=$anchor_id
  printf '{"release_sha":"%s"}\n' "$(printf 'c%.0s' {1..40})" > "$active_state_file"
  printf 'XBOARD_IMAGE=%s\nDB_PASSWORD=DO_NOT_OUTPUT_ME\n' "$digest" > "$workdir/.env"
  printf 'services:\n  web:\n    image: ${XBOARD_IMAGE:?required}\n' > "$workdir/compose.yaml"
  jq -cn --arg id "$anchor_id" --arg root "$workdir" \
    '[{id:$id,project:"xboardme",workdir:$root,config_files:($root+"/compose.yaml")}]' > "$workdir/metadata.json"
  expected="$workdir/expected.json"
  retention_startup_references > "$expected"
  expected_hash=$(_ris_hash_file "$expected")
}
expect_pass() {
  if ! output=$(retention_startup_revalidate "$expected" "$expected_hash" 2> "$workdir/result.log"); then
    echo "STARTUP_REVALIDATION_TEST_FAIL=unexpected_failure:$1" >&2; exit 1
  fi
  [[ -z "$output" && "$(_ris_hash_file "$expected")" == "$expected_hash" ]]
  ((cases+=1))
}
expect_fail() {
  if output=$(retention_startup_revalidate "$expected" "$expected_hash" 2> "$workdir/result.log"); then
    echo "STARTUP_REVALIDATION_TEST_FAIL=unexpected_success:$1" >&2; exit 1
  fi
  [[ -z "$output" ]]
  ! grep -q DO_NOT_OUTPUT_ME "$workdir/result.log"
  ! grep -Eq 'unbound variable|syntax error|command not found' "$workdir/result.log"
  ((cases+=1))
}
fixture
expect_pass unchanged_complete_inputs
fixture
cp -p -- "$workdir/.env" "$workdir/original-env"
sed 's/bbbbbbbbbbbbbbbb/dddddddddddddddd/' "$workdir/original-env" > "$workdir/.env"
touch -r "$workdir/original-env" "$workdir/.env"
[[ "$(stat -c '%s:%Y' "$workdir/.env")" == "$(stat -c '%s:%Y' "$workdir/original-env")" ]]
expect_fail same_size_and_mtime_changed_bytes
for directory in .codex-v2-release .codex-release .codex-stage .codex-admin-hotfix; do
  fixture
  mkdir -p "$workdir/$directory/old version"
  printf 'IMAGE=xboard-rollback:old\n' > "$workdir/$directory/old version/state.env"
  expect_fail newly_discovered_history
done
fixture
rm -- "$workdir/.env"
expect_fail missing_input
fixture
printf 'XBOARD_IMAGE=%s\n' "$digest" > "$workdir/runtime.env"
expect_fail new_indirect_env_not_in_manifest
fixture
printf 'XBOARD_IMAGE=%s\n' "$digest" > "$workdir/runtime.env"
retention_startup_references > "$expected"
expected_hash=$(_ris_hash_file "$expected")
expect_fail existing_unrecorded_indirect_input
fixture
jq '.[0].project="changed"' "$workdir/metadata.json" > "$workdir/next.json"
mv -- "$workdir/next.json" "$workdir/metadata.json"
expect_fail changed_compose_metadata
fixture
mv -- "$workdir/.env" "$workdir/env-original"
ln -s "$workdir/env-original" "$workdir/.env"
expect_fail same_content_symlink
fixture
mv -- "$workdir/.codex-v2-release/123-1" "$workdir/moved"
ln -s "$workdir/moved" "$workdir/.codex-v2-release/123-1"
expect_fail linked_parent
fixture
printf '\000' >> "$workdir/.env"
expect_fail new_nul
fixture
head -c 262145 /dev/zero > "$workdir/.env"
expect_fail oversized_current_file
fixture
jq '.references=[]' "$expected" > "$workdir/next.json"
mv -- "$workdir/next.json" "$expected"
expect_fail altered_expected_manifest
fixture
mkdir -p "$workdir/.codex-stage/space name"
printf 'IMAGE=xboard-rollback:old\n' > "$workdir/.codex-stage/space name/state.env"
retention_startup_references > "$expected"
expected_hash=$(_ris_hash_file "$expected")
expect_pass paths_with_spaces

for tool in realpath stat sha256sum; do
  fixture
  fail_tool=$tool
  _ris_run() {
    timeout --signal=TERM --kill-after=1s 5s "$@" || return
    [[ "$1" != "$fail_tool" ]] || return 42
  }
  expect_fail nonzero_after_valid_output
  source "$script_dir/image-startup-references.sh"
  _ris_compose_metadata() { cat -- "$workdir/metadata.json"; }
done

for mutation in bytes new_file indirect_env manifest metadata symlink; do
  fixture
  # The first batch hash emits valid original output, then the source changes.
  # The second discovery/hash and final identity check must reject this race.
  _ris_run() {
    timeout --signal=TERM --kill-after=1s 5s "$@" || return
    if [[ "$1" == sha256sum && $# -gt 3 && ! -e "$workdir/injected" ]]; then
      touch "$workdir/injected"
      case "$mutation" in
        bytes) printf '# changed\n' >> "$workdir/.env" ;;
        new_file) printf 'IMAGE=xboard-rollback:old\n' > "$workdir/.codex-v2-release/123-1/state.env" ;;
        indirect_env) printf 'XBOARD_IMAGE=%s\n' "$digest" > "$workdir/runtime.env" ;;
        manifest) printf '\n' >> "$expected" ;;
        metadata) printf '[]\n' > "$workdir/metadata.json" ;;
        symlink) mv -- "$workdir/.env" "$workdir/env-original"; ln -s "$workdir/env-original" "$workdir/.env" ;;
      esac
    fi
  }
  expect_fail change_after_first_batch_hash
  source "$script_dir/image-startup-references.sh"
  _ris_compose_metadata() { cat -- "$workdir/metadata.json"; }
done

fixture
cp -- "$expected" "$workdir/original-manifest"
_ris_run() {
  timeout --signal=TERM --kill-after=1s 5s "$@" || return
  if [[ "$1" == sha256sum && "${3:-}" == "$expected" && ! -e "$workdir/injected" ]]; then
    touch "$workdir/injected"
    printf 'XBOARD_PREVIOUS_IMAGE=xboard-rollback:new\n' >> "$workdir/.env"
    local replacement
    replacement=$(sha256sum -- "$workdir/.env"); replacement=${replacement%% *}
    jq --arg path "$workdir/.env" --arg hash "$replacement" \
      '(.files[]|select(.path==$path)|.sha256)=$hash' "$expected" > "$workdir/next.json"
    mv -- "$workdir/next.json" "$expected"
  elif [[ "$1" == stat && $# -gt 5 && -e "$workdir/injected" ]]; then
    cp -- "$workdir/original-manifest" "$expected"
  fi
}
expect_fail manifest_aba_and_changed_sources
source "$script_dir/image-startup-references.sh"
_ris_compose_metadata() { cat -- "$workdir/metadata.json"; }

for tool in stat realpath; do
  fixture
  fail_tool=$tool
  _ris_run() {
    timeout --signal=TERM --kill-after=1s 5s "$@" || return
    if [[ "$1" == "$fail_tool" && $# -gt 4 &&
          ( "$fail_tool" == realpath || "$3" == '%d:%i:%s:%y:%z' ) ]]; then
      local count=0
      [[ ! -f "$workdir/calls" ]] || read -r count < "$workdir/calls"
      count=$((count+1)); printf '%s\n' "$count" > "$workdir/calls"
      ((count!=4)) || return 42
    fi
  }
  expect_fail final_dependency_nonzero_after_output
  source "$script_dir/image-startup-references.sh"
  _ris_compose_metadata() { cat -- "$workdir/metadata.json"; }
done
printf 'STARTUP_REVALIDATION_TEST=PASS cases=%s production_connections=0\n' "$cases"
