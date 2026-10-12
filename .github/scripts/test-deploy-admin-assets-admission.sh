#!/usr/bin/env bash
set -Eeuo pipefail

# Offline guard integration test. Docker and discovery are strict fakes; the
# first payload-directory write is intercepted. No daemon or host is contacted.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/release-state.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
cases=0

run_case() (
  set -Eeuo pipefail
  local name=$1 fixture="$test_root/$1" status expected=cleanup_admission_helper_missing
  mkdir -p "$fixture/work/.codex-v2-release"
  chmod 700 "$fixture/work/.codex-v2-release"
  : > "$fixture/work/.codex-v2-release/deploy.lock"
  chmod 600 "$fixture/work/.codex-v2-release/deploy.lock"
  : > "$fixture/mutations.log"
  HOTFIX_ID=555-1
  STAGE_RUN_ID=444-1
  RELEASE_SHA=$(printf 'a%.0s' {1..40})
  local digest="ghcr.io/example/xboard@sha256:$(printf 'b%.0s' {1..64})"
  local gate="$fixture/work/.codex-v2-release/image-cleanup-gate.json"
  case "$name" in
    missing-helper) printf '{"status":"HOLD"}\n' > "$gate" ;;
    hold|malformed|symlink-gate)
      source "$script_dir/retention-continuity.sh"
      if [[ "$name" == hold ]]; then
        jq -n --argjson owner "$EUID" --arg sha "$RELEASE_SHA" \
          --arg hash "$(printf 'c%.0s' {1..64})" '{schema_version:1,status:"HOLD",owner_uid:$owner,
          session_id:"aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",workflow_sha:$sha,active_release_sha:$sha,
          active_release_id:"333-1",review_sha256:$hash,protection_fingerprint:$hash,
          image_id:("sha256:"+$hash),started_at_uptime:1,sample_count:1,baseline_seconds:600,
          observe_seconds:900,sample_interval_seconds:10,post_verification:"pending",
          failure_reason:"test_failure",cleanup_validated:false}' > "$gate"
      elif [[ "$name" == malformed ]]; then
        printf '{}\n' > "$gate"
      else
        ln -s "$fixture/not-existing" "$gate"
      fi
      [[ -L "$gate" ]] || chmod 600 "$gate"
      expected=cleanup_admission_blocked
      ;;
    locked)
      exec 8<>"$fixture/work/.codex-v2-release/deploy.lock"
      flock -n 8
      expected=deployment_locked
      ;;
    symlink-lock)
      rm -- "$fixture/work/.codex-v2-release/deploy.lock"
      printf 'preserve\n' > "$fixture/unrelated"
      ln -s "$fixture/unrelated" "$fixture/work/.codex-v2-release/deploy.lock"
      expected=invalid_deploy_lock
      ;;
    hardlink-lock)
      ln "$fixture/work/.codex-v2-release/deploy.lock" "$fixture/unrelated"
      expected=invalid_deploy_lock
      ;;
    missing-gate) expected=TEST_PAYLOAD_BOUNDARY ;;
    *) return 99 ;;
  esac
  docker() {
    case "$*" in
      'ps -q --filter label=codex.xboard.stage=true --filter label=codex.xboard.stage.run=444-1') printf 'stage-id\n' ;;
      'inspect -f {{.Config.Image}} stage-id') printf '%s\n' "$digest" ;;
      'image inspect -f {{ index .Config.Labels "org.opencontainers.image.revision" }} '*) printf '%s\n' "$RELEASE_SHA" ;;
      'exec stage-id php /www/.github/scripts/verify-admin-assets.php') return 0 ;;
      *) printf 'unexpected-docker\n' >> "$fixture/mutations.log"; return 99 ;;
    esac
  }
  xboard_find_caddy_upstream() { XBOARD_ACTIVE_PORT=7002; }
  xboard_resolve_active_runtime() { XBOARD_ACTIVE_WEB=active-id; }
  xboard_find_compose_anchor() { XBOARD_ANCHOR_WORKDIR="$fixture/work"; }
  mkdir() {
    if [[ "$*" == *'/.codex-admin-hotfix/'* ]]; then
      echo TEST_PAYLOAD_BOUNDARY >&2
      return 97
    fi
    command mkdir "$@"
  }
  set +e
  ( set -Eeuo pipefail; source "$script_dir/deploy-admin-assets-hotfix.sh" ) > "$fixture/result.log" 2>&1
  status=$?
  set -e
  ((status != 0)) || { echo "ADMIN_ADMISSION_TEST_FAIL=unexpected_success:$name" >&2; return 1; }
  grep -Fq "$expected" "$fixture/result.log" || { cat "$fixture/result.log" >&2; return 1; }
  [[ ! -s "$fixture/mutations.log" && ! -e "$fixture/work/.codex-admin-hotfix" ]]
  if [[ "$name" == symlink-lock ]]; then [[ "$(cat "$fixture/unrelated")" == preserve ]]; fi
  if [[ "$name" != missing-gate ]]; then ! grep -Fq TEST_PAYLOAD_BOUNDARY "$fixture/result.log"; fi
)

for name in missing-helper hold malformed symlink-gate locked symlink-lock hardlink-lock missing-gate; do
  run_case "$name"
  ((cases+=1))
  printf 'ADMIN_ASSET_ADMISSION_CASE=PASS name=%s\n' "$name"
done
printf 'ADMIN_ASSET_ADMISSION_TEST=PASS cases=%s real_docker=false\n' "$cases"
