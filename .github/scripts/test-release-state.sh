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

echo 'RELEASE_STATE_TEST=PASS'
