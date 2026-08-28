#!/usr/bin/env bash
set -Eeuo pipefail

: "${EXPECTED_SHA:?EXPECTED_SHA is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${GITHUB_OUTPUT:?GITHUB_OUTPUT is required}"
: "${RUNNER_TEMP:?RUNNER_TEMP is required}"

[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || {
  echo 'PRODUCTION_IMAGE_RESOLVE_FAIL=invalid_sha' >&2
  exit 1
}
[[ "$GITHUB_REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || {
  echo 'PRODUCTION_IMAGE_RESOLVE_FAIL=invalid_repository' >&2
  exit 1
}
command -v gh >/dev/null
command -v jq >/dev/null
command -v unzip >/dev/null
command -v php >/dev/null

work_dir=$(mktemp -d "$RUNNER_TEMP/production-image.XXXXXX")
cleanup() {
  rm -rf -- "$work_dir"
}
trap cleanup EXIT

runs_json="$work_dir/runs.json"
gh api "/repos/$GITHUB_REPOSITORY/actions/workflows/docker-publish.yml/runs?branch=codex%2Fdistributor&head_sha=$EXPECTED_SHA&event=push&status=success&per_page=100" > "$runs_json"
run_count=$(jq --arg sha "$EXPECTED_SHA" '
  [.workflow_runs[] |
    select(.head_sha == $sha and
           .head_branch == "codex/distributor" and
           .event == "push" and
           .status == "completed" and
           .conclusion == "success")]
  | length
' "$runs_json")
[[ "$run_count" == 1 ]] || {
  echo "PRODUCTION_IMAGE_RESOLVE_FAIL=canonical_build_count:$run_count" >&2
  exit 1
}
build_run_id=$(jq -r --arg sha "$EXPECTED_SHA" '
  .workflow_runs[] |
  select(.head_sha == $sha and
         .head_branch == "codex/distributor" and
         .event == "push" and
         .status == "completed" and
         .conclusion == "success") |
  .id
' "$runs_json")
[[ "$build_run_id" =~ ^[1-9][0-9]*$ ]] || {
  echo 'PRODUCTION_IMAGE_RESOLVE_FAIL=invalid_build_run_id' >&2
  exit 1
}

artifacts_json="$work_dir/artifacts.json"
gh api "/repos/$GITHUB_REPOSITORY/actions/runs/$build_run_id/artifacts?per_page=100" > "$artifacts_json"
artifact_name="production-image-$EXPECTED_SHA"
artifact_count=$(jq --arg name "$artifact_name" '[.artifacts[] | select(.name == $name and .expired == false)] | length' "$artifacts_json")
[[ "$artifact_count" == 1 ]] || {
  echo "PRODUCTION_IMAGE_RESOLVE_FAIL=manifest_artifact_count:$artifact_count" >&2
  exit 1
}
artifact_id=$(jq -r --arg name "$artifact_name" '.artifacts[] | select(.name == $name and .expired == false) | .id' "$artifacts_json")
[[ "$artifact_id" =~ ^[1-9][0-9]*$ ]] || {
  echo 'PRODUCTION_IMAGE_RESOLVE_FAIL=invalid_artifact_id' >&2
  exit 1
}
artifact_size=$(jq -r --arg name "$artifact_name" '.artifacts[] | select(.name == $name and .expired == false) | .size_in_bytes' "$artifacts_json")
[[ "$artifact_size" =~ ^[1-9][0-9]*$ ]] && ((artifact_size <= 65536)) || {
  echo 'PRODUCTION_IMAGE_RESOLVE_FAIL=invalid_artifact_size' >&2
  exit 1
}

artifact_zip="$work_dir/manifest.zip"
gh api "/repos/$GITHUB_REPOSITORY/actions/artifacts/$artifact_id/zip" > "$artifact_zip"
mapfile -t archive_entries < <(unzip -Z1 "$artifact_zip")
if ((${#archive_entries[@]} != 1)) || [[ "${archive_entries[0]}" != production-image.json ]]; then
  echo 'PRODUCTION_IMAGE_RESOLVE_FAIL=unexpected_artifact_contents' >&2
  exit 1
fi
unzip -q "$artifact_zip" -d "$work_dir/manifest"
manifest="$work_dir/manifest/production-image.json"
php "$GITHUB_WORKSPACE/.github/scripts/production-image-manifest.php" \
  verify "$manifest" "$GITHUB_REPOSITORY" "$EXPECTED_SHA" "$build_run_id"

image=$(jq -r '.image' "$manifest")
digest=$(jq -r '.digest' "$manifest")
image_ref=$(jq -r '.image_ref' "$manifest")
[[ "$image_ref" == "$image@$digest" ]] || {
  echo 'PRODUCTION_IMAGE_RESOLVE_FAIL=image_reference_mismatch' >&2
  exit 1
}

printf 'image=%s\n' "$image" >> "$GITHUB_OUTPUT"
printf 'digest=%s\n' "$digest" >> "$GITHUB_OUTPUT"
printf 'image_ref=%s\n' "$image_ref" >> "$GITHUB_OUTPUT"
printf 'build_run_id=%s\n' "$build_run_id" >> "$GITHUB_OUTPUT"
echo "PRODUCTION_IMAGE_RESOLVE=PASS sha=$EXPECTED_SHA digest=$digest build_run_id=$build_run_id"
