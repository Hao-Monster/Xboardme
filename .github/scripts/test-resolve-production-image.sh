#!/usr/bin/env bash
set -Eeuo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repository_root=$(cd -- "$script_dir/../.." && pwd)
work_dir=$(mktemp -d)
cleanup() {
  rm -rf -- "$work_dir"
}
trap cleanup EXIT

sha=$(printf 'a%.0s' {1..40})
digest="sha256:$(printf 'b%.0s' {1..64})"
repository='Hao-Monster/Xboardme'
mkdir -p "$work_dir/artifact" "$work_dir/mock-bin" "$work_dir/runner"
php "$script_dir/production-image-manifest.php" create \
  "$work_dir/artifact/production-image.json" \
  "$repository" "$sha" ghcr.io/hao-monster/xboardme "$digest" 12345 >/dev/null
(
  cd "$work_dir/artifact"
  zip -q "$work_dir/manifest.zip" production-image.json
)

cat > "$work_dir/mock-bin/gh" <<'MOCK'
#!/usr/bin/env bash
set -Eeuo pipefail

request=${2:-}
case "$request" in
  */actions/workflows/docker-publish.yml/runs\?*)
    if [[ "${MOCK_DUPLICATE_BUILD:-false}" == true ]]; then
      printf '%s\n' "{\"workflow_runs\":[
        {\"id\":12345,\"head_sha\":\"$MOCK_SHA\",\"head_branch\":\"codex/distributor\",\"event\":\"push\",\"status\":\"completed\",\"conclusion\":\"success\"},
        {\"id\":12346,\"head_sha\":\"$MOCK_SHA\",\"head_branch\":\"codex/distributor\",\"event\":\"push\",\"status\":\"completed\",\"conclusion\":\"success\"}
      ]}"
    else
      printf '%s\n' "{\"workflow_runs\":[
        {\"id\":12345,\"head_sha\":\"$MOCK_SHA\",\"head_branch\":\"codex/distributor\",\"event\":\"push\",\"status\":\"completed\",\"conclusion\":\"success\"}
      ]}"
    fi
    ;;
  */actions/runs/12345/artifacts\?*)
    artifact_size=512
    if [[ "${MOCK_OVERSIZED_ARTIFACT:-false}" == true ]]; then
      artifact_size=70000
    fi
    printf '%s\n' "{\"artifacts\":[{\"id\":67890,\"name\":\"production-image-$MOCK_SHA\",\"expired\":false,\"size_in_bytes\":$artifact_size}]}"
    ;;
  */actions/artifacts/67890/zip)
    command cat "$MOCK_ROOT/manifest.zip"
    ;;
  *)
    echo "Unexpected gh request: $request" >&2
    exit 1
    ;;
esac
MOCK
chmod +x "$work_dir/mock-bin/gh"

output_file="$work_dir/output"
PATH="$work_dir/mock-bin:$PATH" \
MOCK_ROOT="$work_dir" \
MOCK_SHA="$sha" \
EXPECTED_SHA="$sha" \
GITHUB_REPOSITORY="$repository" \
GITHUB_WORKSPACE="$repository_root" \
GITHUB_OUTPUT="$output_file" \
RUNNER_TEMP="$work_dir/runner" \
  bash "$script_dir/resolve-production-image.sh" >/dev/null

grep -Fxq 'image=ghcr.io/hao-monster/xboardme' "$output_file"
grep -Fxq "digest=$digest" "$output_file"
grep -Fxq "image_ref=ghcr.io/hao-monster/xboardme@$digest" "$output_file"
grep -Fxq 'build_run_id=12345' "$output_file"

if PATH="$work_dir/mock-bin:$PATH" \
  MOCK_ROOT="$work_dir" \
  MOCK_SHA="$sha" \
  MOCK_DUPLICATE_BUILD=true \
  EXPECTED_SHA="$sha" \
  GITHUB_REPOSITORY="$repository" \
  GITHUB_WORKSPACE="$repository_root" \
  GITHUB_OUTPUT="$work_dir/duplicate-output" \
  RUNNER_TEMP="$work_dir/runner" \
    bash "$script_dir/resolve-production-image.sh" >/dev/null 2>&1; then
  echo 'Duplicate canonical builds unexpectedly resolved.' >&2
  exit 1
fi

if PATH="$work_dir/mock-bin:$PATH" \
  MOCK_ROOT="$work_dir" \
  MOCK_SHA="$sha" \
  MOCK_OVERSIZED_ARTIFACT=true \
  EXPECTED_SHA="$sha" \
  GITHUB_REPOSITORY="$repository" \
  GITHUB_WORKSPACE="$repository_root" \
  GITHUB_OUTPUT="$work_dir/oversized-output" \
  RUNNER_TEMP="$work_dir/runner" \
    bash "$script_dir/resolve-production-image.sh" >/dev/null 2>&1; then
  echo 'Oversized manifest artifact unexpectedly resolved.' >&2
  exit 1
fi

echo 'Production image resolver tests passed.'
