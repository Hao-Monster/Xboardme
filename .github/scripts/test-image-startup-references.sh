#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-safety.sh"
source "$script_dir/image-startup-references.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
digest="ghcr.io/hao-monster/xboardme@sha256:$(printf 'a%.0s' {1..64})"
image_id="sha256:$(printf 'c%.0s' {1..64})"
anchor_id=$(printf 'd%.0s' {1..64})
cases=0
# Only Docker metadata discovery is synthetic; parsing/file checks are genuine.
_ris_compose_metadata() { [[ ! -f "$workdir/docker-fails" ]] && cat -- "$workdir/docker-metadata.json"; }
fixture() {
  workdir="$test_root/case-$cases"
  mkdir -p "$workdir/.codex-v2-release/123-1"
  active_state_file="$workdir/.codex-v2-release/123-1/state.json"
  XBOARD_ANCHOR_CONTAINER=$anchor_id
  active_project=xboard-v2-123-1
  jq -n --arg image "$digest" '{release_id:"123-1",release_sha:("b"*40),release_image:$image,
    previous_image:"xboard-rollback:previous",password:"DO_NOT_OUTPUT_ME"}' > "$active_state_file"
  printf 'DB_PASSWORD=DO_NOT_OUTPUT_ME\n' > "$workdir/.env"
  printf 'services:\n  web:\n    image: %s\n' "$digest" > "$workdir/compose.yaml"
  jq -cn --arg id "$anchor_id" --arg root "$workdir" '[{id:$id,project:"xboardme",workdir:$root,
    config_files:($root+"/compose.yaml")}]' > "$workdir/docker-metadata.json"
}
expect_pass() {
  if ! result=$(retention_startup_references); then echo "IMAGE_STARTUP_TEST_FAIL=unexpected_failure:$1" >&2; exit 1; fi
  [[ $result != *DO_NOT_OUTPUT_ME* && $result != *CHANGED_SECRET* ]]
  ((cases+=1))
}
expect_fail() {
  if result=$(retention_startup_references 2> "$workdir/failure.log"); then
    echo "IMAGE_STARTUP_TEST_FAIL=unexpected_success:$1" >&2; exit 1
  fi
  [[ $result != *DO_NOT_OUTPUT_ME* ]]
  ((cases+=1))
}
fixture
printf 'services:\n  web:\n    image: ${XBOARD_IMAGE:?required}\n' > "$workdir/compose.v2.sample.yaml"
expect_pass unused_sample_is_not_startup_source
jq -e --arg digest "$digest" '.schema_version==1 and (.files|length)==3 and
  (.digests|index($digest)!=null) and (.revisions|index("b"*40)!=null) and
  (.references|index("xboard-rollback:previous")!=null)' <<< "$result" >/dev/null
first=$result
[[ "$first" == "$(retention_startup_references)" ]]
printf 'DB_PASSWORD=CHANGED_SECRET\n' > "$workdir/.env"
[[ "$first" != "$(retention_startup_references)" ]]
((cases+=1))
fixture
printf 'XBOARD_IMAGE=%s\n' "$digest" > "$workdir/.env"
printf 'services:\n  web:\n    image: ${XBOARD_IMAGE:?required}\n' > "$workdir/compose.sample.yaml"
jq '.[0].config_files|=sub("compose.yaml$";"compose.sample.yaml")' "$workdir/docker-metadata.json" > "$workdir/next.json"
mv -- "$workdir/next.json" "$workdir/docker-metadata.json"
expect_pass actually_used_sample_is_read
grep -q 'compose.sample.yaml' <<< "$result"
printf 'services:\n  web:\n    image: ${UNKNOWN_IMAGE}\n' > "$workdir/compose.sample.yaml"
expect_fail used_sample_unknown_interpolation
fixture
cp -- "$script_dir/../../compose.v2.sample.yaml" "$workdir/.codex-v2-release/123-1/compose.v2.sample.yaml"
cp -- "$script_dir/../../compose.v2.production.yaml" "$workdir/.codex-v2-release/123-1/compose.v2.production.yaml"
printf 'XBOARD_IMAGE=%s\n' "$digest" > "$workdir/.codex-v2-release/123-1/runtime.env"
expect_pass real_v2_mapping_anchors_and_overlay
for literal in "$image_id" "${image_id#sha256:}" xboard-rollback:old xboard-rollback \
  'ghcr.io/hao-monster/xboardme:startup' 'caddy:2.10.2-alpine'; do
  fixture
  printf 'services:\n  web:\n    image: %s\n' "$literal" > "$workdir/compose.yaml"
  expect_pass literal_reference
  if [[ $literal == sha256:* || $literal =~ ^[a-f0-9]{64}$ ]]; then
    jq -e --arg id "$image_id" '.image_ids|index($id)!=null' <<< "$result" >/dev/null
  else
    expected=$literal
    [[ $expected == *:* ]] || expected+=:latest
    jq -e --arg reference "$expected" '.references|index($reference)!=null' <<< "$result" >/dev/null
  fi
done
for scalar in '"ghcr.io/hao-monster/xboardme:quoted" # comment' "'xboard-rollback:quoted'"; do
  fixture
  printf 'services:\n  web:\n    image: %s\n' "$scalar" > "$workdir/compose.yaml"
  expect_pass exact_quoted_literal
done
for origin in yaml env state; do
  fixture
  unique_digest="ghcr.io/hao-monster/xboardme@sha256:$(printf 'f%.0s' {1..64})"
  tagged_digest="${unique_digest/@/:old@}"
  case "$origin" in
    yaml) printf 'services:\n  web:\n    image: %s\n' "$tagged_digest" > "$workdir/compose.yaml" ;;
    env) printf 'XBOARD_PREVIOUS_IMAGE=%s\n' "$tagged_digest" > "$workdir/.env" ;;
    state)
      jq --arg image "$tagged_digest" '.previous_image=$image' "$active_state_file" > "$workdir/next.json"
      mv -- "$workdir/next.json" "$active_state_file"
      ;;
  esac
  expect_pass tagged_digest_canonicalization
  jq -e --arg reference "$tagged_digest" --arg digest "$unique_digest" \
    '(.digests|index($digest)!=null) and (.references|index($reference)!=null)' <<< "$result" >/dev/null
done
for alias in docker.io/library/xboard-rollback:old index.docker.io/library/xboard-rollback:old \
  library/xboard-rollback:old docker.io/xboard-rollback:old; do
  fixture
  printf 'services:\n  web:\n    image: %s\n' "$alias" > "$workdir/compose.yaml"
  expect_pass docker_familiar_alias_canonicalization
  jq -e '.references|index("xboard-rollback:old")!=null' <<< "$result" >/dev/null
done
for scalar in '${XBOARD_IMAGE}-old' 'prefix-${XBOARD_IMAGE}' '${UNKNOWN_IMAGE}' '$XBOARD_IMAGE' \
  '${XBOARD_IMAGE:-fallback}' '${XBOARD_IMAGE} ${OTHER}' '*image_alias' '&image_alias xboard-rollback:old' \
  '{name: xboard-rollback:old}' '[xboard-rollback:old]' '!!str xboard-rollback:old' \
  '|' '>' '' 'sha256:1234' 'abcdef123456' 'https://user:DO_NOT_OUTPUT_ME@registry/image' \
  '"xboard-rollback:\u006fld"'; do
  fixture
  printf 'XBOARD_IMAGE=%s\n' "$digest" > "$workdir/.env"
  printf 'services:\n  web:\n    image: %s\n' "$scalar" > "$workdir/compose.yaml"
  expect_fail unsupported_scalar
done
for yaml in 'include: other.yaml' 'services: {web: {image: xboard-rollback:old}}' \
  $'services:\n  web:\n    image : xboard-rollback:old' 'include : other.yaml' \
  'services: {web: {"im\u0061ge": xboard-rollback:old}}' \
  $'services:\n  web:\n    extends:\n      file: other.yaml\n      service: web' \
  $'services:\n  web:\n    "image": xboard-rollback:old' \
  $'services:\n  web:\n    !<tag:yaml.org,2002:str> image: xboard-rollback:old' \
  $'services:\n  web:\n    ? image\n    : xboard-rollback:old' \
  $'services:\n  web:\n    <<: *unknown'; do
  fixture
  printf '%s\n' "$yaml" > "$workdir/compose.yaml"
  expect_fail unsupported_yaml
done
for mutation in '.release_image={secret:"DO_NOT_OUTPUT_ME"}' '.release_image=null' '.release_sha=123' \
  '.release_image="${UNRESOLVED}"' '.image_id="sha256:1234"' '.release_sha="not-a-sha"'; do
  fixture
  jq "$mutation" "$active_state_file" > "$workdir/next.json"
  mv -- "$workdir/next.json" "$active_state_file"
  expect_fail malformed_state_field
done
fixture
mkdir -p "$workdir/.codex-v2-release/122-1"
jq -n --arg image "$image_id" '{release_sha:("e"*40),image_id:$image}' > "$workdir/.codex-v2-release/122-1/state.json"
expect_pass all_historical_states
jq -e --arg id "$image_id" '(.image_ids|index($id)!=null) and (.revisions|index("e"*40)!=null)' <<< "$result" >/dev/null
fixture
mkdir -p "$workdir/.codex-admin-hotfix/122-1"
printf 'RELEASE_IMAGE=xboard-rollback:legacy-env\nRELEASE_SHA=%s\n' "$(printf 'e%.0s' {1..40})" > "$workdir/.codex-admin-hotfix/122-1/state.env"
expect_pass legacy_shell_state_not_evaluated
jq -e '(.references|index("xboard-rollback:legacy-env")!=null) and (.revisions|index("e"*40)!=null)' <<< "$result" >/dev/null
printf 'RELEASE_IMAGE=$(touch DO_NOT_EXECUTE)\n' > "$workdir/.codex-admin-hotfix/122-1/state.env"
expect_fail legacy_shell_state_expression_rejected
for dotenv in 'XBOARD_IMAGE=${UNKNOWN}' 'export XBOARD_IMAGE=${UNKNOWN}' 'XBOARD_IMAGE: hidden' \
  'XBOARD_IMAGE="xboard-rollback:\u006fld"'; do
  fixture
  printf '%s\n' "$dotenv" > "$workdir/.env"
  expect_fail unsupported_env
done
fixture
printf 'export XBOARD_IMAGE="xboard-rollback:env"\nXBOARD_PREVIOUS_IMAGE=%s\n' "$image_id" > "$workdir/.env"
expect_pass literal_env
jq -e --arg id "$image_id" '(.image_ids|index($id)!=null) and (.references|index("xboard-rollback:env")!=null)' <<< "$result" >/dev/null
fixture
mv -- "$workdir/compose.yaml" "$workdir/original.yaml"
ln -s "$workdir/original.yaml" "$workdir/compose.yaml"
expect_fail selected_symlink
fixture
ln -s "$workdir/.env" "$workdir/compose.unused.sample.yaml"
expect_pass unused_template_link_not_followed
fixture
ln -s "$test_root" "$workdir/.codex-v2-release/124-1"
expect_fail linked_history
fixture
mv -- "$workdir/.env" "$workdir/real-env"
ln -s "$workdir/real-env" "$workdir/.env"
expect_fail linked_env
fixture
mkdir "$workdir/.codex-v2-release/123-1/runtime.env"
expect_fail non_file_history_input
for mutation in '.[0].config_files="/tmp/outside.yaml"' '.[0].config_files=""' \
  '.[0].config_files+=","' '.[0].workdir="/outside"' '.[0].config_files=12' \
  '.[0].config_files+=".missing"'; do
  fixture
  jq "$mutation" "$workdir/docker-metadata.json" > "$workdir/next.json"
  mv -- "$workdir/next.json" "$workdir/docker-metadata.json"
  expect_fail untrusted_metadata
done
fixture
touch "$workdir/docker-fails"
expect_fail docker_failure
fixture
printf '{broken' > "$active_state_file"
expect_fail invalid_json
fixture
head -c 262145 /dev/zero > "$workdir/compose.yaml"
expect_fail oversized_file
fixture
printf 'services:\n  web:\n    im\000age: xboard-rollback:old\n' > "$workdir/compose.yaml"
expect_fail null_byte
fixture
# Propagate nonzero even when a dependency emitted otherwise valid output.
_ris_run() {
  timeout --signal=TERM --kill-after=1s 5s "$@" || return
  [[ $1 != realpath ]] || return 1
}
expect_fail canonicalization_nonzero_after_output
source "$script_dir/image-startup-references.sh"
_ris_compose_metadata() { [[ ! -f "$workdir/docker-fails" ]] && cat -- "$workdir/docker-metadata.json"; }
fixture
_ris_run() {
  timeout --signal=TERM --kill-after=1s 5s "$@" || return
  [[ $1 != jq || $2 != -Rs ]] || return 1
}
expect_fail aggregation_nonzero_after_output
source "$script_dir/image-startup-references.sh"
fixture
# source restored real discovery; restore the explicit test-only metadata boundary.
_ris_compose_metadata() { [[ ! -f "$workdir/docker-fails" ]] && cat -- "$workdir/docker-metadata.json"; }
expect_pass dependency_override_restored
printf 'IMAGE_STARTUP_TEST=PASS cases=%s production_connections=0 docker_metadata=synthetic\n' "$cases"
