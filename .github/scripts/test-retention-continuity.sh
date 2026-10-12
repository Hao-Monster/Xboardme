#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# These are isolated synthetic state-machine tests, not production continuity,
# Docker performance, SSH-loss, or monitoring-evidence acceptance.
source "$script_dir/retention-continuity.sh"
declare -F retention_continuity_begin >/dev/null
declare -F retention_continuity_check >/dev/null
declare -F retention_continuity_intent >/dev/null
declare -F retention_continuity_observe >/dev/null
declare -F retention_continuity_complete >/dev/null
declare -F retention_continuity_hold >/dev/null

test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
export EXPECTED_WORKFLOW_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export EXPECTED_ACTIVE_RELEASE_ID=123-1
export EXPECTED_ACTIVE_RELEASE_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
export EXPECTED_RETENTION_REVIEW_SHA256=''
export XBOARD_ACTIVE_PORT=7002
test_image="sha256:$(printf 'c%.0s' {1..64})"
fixture() {
  workdir="$test_root/$1"
  mkdir -p "$workdir/.codex-v2-release"
  chmod 700 "$workdir/.codex-v2-release"
  exec 9>"$workdir/.codex-v2-release/deploy.lock"
  chmod 600 "$workdir/.codex-v2-release/deploy.lock"
  flock -n 9
  jq -n --arg workflow "$EXPECTED_WORKFLOW_SHA" --arg active "$EXPECTED_ACTIVE_RELEASE_SHA" --arg image "$test_image" '
    ("d"*64) as $h | {
      schema_version:2,status:"approved",operation:"image_cleanup",active_release_id:"123-1",
      active_release_sha:$active,resource_fingerprint:$h,protection_fingerprint:$h,
      containers:[],images:[{id:$image}],
      monitoring:{baseline_seconds:600,post_delete_observe_seconds:900,sample_interval_seconds:10,
        heartbeat_max_age_seconds:30,
        public:{url:"https://example.invalid/api/readonly",expected_status:200,body_sha256:$h,max_latency_ms:2000},
        origin:{url:"http://127.0.0.1:7002/api/readonly",expected_status:200,body_sha256:$h,max_latency_ms:2000},
        caddy:{unit:"caddy.service",invocation_id:("e"*32),main_pid:42,active_enter_monotonic_usec:1000000},
        protected_containers:[range(1;9)|{id:((tostring)*64),status:"running",started_at:"2026-10-12T00:00:00Z",restart_count:0}],
        resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,
          max_io_full_avg10:1,max_cpu_some_avg10:10},
        evidence:{read_only_endpoints_sha256:$h,production_baseline_sha256:$h,long_connections_sha256:$h,
          natural_traffic_sha256:$h,resource_overhead_sha256:$h},
        live_signals:{transport:"host_json_v1",source_review_sha256:$h,max_age_seconds:20}}
    }' > "$workdir/review.json"
  review="$workdir/review.json"
  EXPECTED_RETENTION_REVIEW_SHA256=$(sha256sum "$review" | cut -d' ' -f1)
  RC_OBSERVER_PID=''
  RC_TEST_NOW=1000
  RC_TEST_FAILURE=''
  RC_TEST_IMAGE_PRESENT=false
}

# Test-only function overrides are deliberately outside the production module.
# No environment variable or workflow input can shorten its production bounds.
_rc_now() { printf '%s\n' "$RC_TEST_NOW"; }
_rc_wait() { RC_TEST_NOW=$((RC_TEST_NOW + 10)); _rc_sample >/dev/null; }
_rc_start_observer() { _rc_sample >/dev/null; }
_rc_stop_observer() { :; }
_rc_probe() { [[ -z "$RC_TEST_FAILURE" ]]; }
_rc_image_absent() { [[ "$RC_TEST_IMAGE_PRESENT" == false ]]; }

fail() { echo "RETENTION_CONTINUITY_TEST=FAIL $*" >&2; exit 1; }
expect_failure() { if "$@" > "$workdir/failure.log" 2>&1; then fail "unexpected_success:$*"; fi; }
status_is() { jq -e --arg status "$1" '.status==$status' "$RC_GATE" >/dev/null; }
cases=0

fixture happy
retention_continuity_validate_review "$review"
retention_continuity_begin "$review"
[[ $RC_TEST_NOW -ge 1600 ]]
retention_continuity_intent "$test_image"
retention_continuity_observe "$test_image"
[[ $RC_TEST_NOW -ge 2500 ]]
retention_continuity_complete
status_is COMPLETE
expect_failure retention_continuity_admit_deployment "$workdir"
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$(printf 'f%.0s' {1..64})"
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$(printf 'f%.0s' {1..64})"
retention_continuity_admit_deployment "$workdir"
expect_failure retention_continuity_begin "$review"
((cases+=1))

# A new approval is not a retry. Preserve exact old evidence, refuse collisions.
old_session=$(jq -r .session_id "$RC_GATE")
old_hash=$(sha256sum "$RC_GATE" | cut -d' ' -f1)
new_image="sha256:$(printf 'e%.0s' {1..64})"
jq --arg image "$new_image" '.images[0].id=$image' "$review" > "$workdir/new-review.json"
review="$workdir/new-review.json"
EXPECTED_RETENTION_REVIEW_SHA256=$(sha256sum "$review" | cut -d' ' -f1)
printf 'conflicting history\n' > "$RC_ROOT/image-cleanup-gate-history-$old_session.json"
chmod 600 "$RC_ROOT/image-cleanup-gate-history-$old_session.json"
expect_failure retention_continuity_begin "$review"
status_is COMPLETE
((cases+=1))
rm -- "$RC_ROOT/image-cleanup-gate-history-$old_session.json"
retention_continuity_begin "$review"
status_is RUNNING
[[ $(sha256sum "$RC_ROOT/image-cleanup-gate-history-$old_session.json" | cut -d' ' -f1) == "$old_hash" ]]
retention_continuity_hold fixture_done
((cases+=1))

for selector in '.monitoring.baseline_seconds=599' '.monitoring.post_delete_observe_seconds=899' \
  '.monitoring.sample_interval_seconds=11' '.monitoring.heartbeat_max_age_seconds=999' \
  '.monitoring.evidence.long_connections_sha256=""' '.monitoring.origin.url="http://example.invalid/"' \
  '.monitoring.public.url="https://user:password@example.invalid/"' '.monitoring.public.expected_status=503' \
  '.monitoring.resources.max_cpu_busy_percent=101' '.monitoring.protected_containers=[]'; do
  fixture "invalid-$cases"
  jq "$selector" "$review" > "$workdir/invalid.json"
  expect_failure retention_continuity_validate_review "$workdir/invalid.json"
  ((cases+=1))
done

fixture heartbeat
retention_continuity_begin "$review"
RC_TEST_NOW=$((RC_TEST_NOW+31))
expect_failure retention_continuity_check
status_is HOLD
expect_failure retention_continuity_begin "$review"
expect_failure retention_continuity_admit_deployment "$workdir"
((cases+=1))

fixture unhealthy
retention_continuity_begin "$review"
RC_TEST_FAILURE=timeout
expect_failure _rc_sample
status_is HOLD
RC_TEST_FAILURE=''
expect_failure retention_continuity_check
((cases+=1))

fixture pending
retention_continuity_begin "$review"
retention_continuity_intent "$test_image"
expect_failure retention_continuity_complete
status_is HOLD
((cases+=1))

fixture still-present
retention_continuity_begin "$review"
retention_continuity_intent "$test_image"
RC_TEST_IMAGE_PRESENT=true
expect_failure retention_continuity_observe "$test_image"
status_is HOLD
((cases+=1))

fixture wrong-image
retention_continuity_begin "$review"
expect_failure retention_continuity_intent "sha256:$(printf 'f%.0s' {1..64})"
status_is HOLD
((cases+=1))

fixture orphaned-running
retention_continuity_begin "$review"
expect_failure retention_continuity_begin "$review"
status_is RUNNING
expect_failure retention_continuity_admit_deployment "$workdir"
((cases+=1))

fixture cancelled
retention_continuity_begin "$review"
retention_continuity_hold cancelled
status_is HOLD
expect_failure retention_continuity_complete
saved_workflow=$EXPECTED_WORKFLOW_SHA
EXPECTED_WORKFLOW_SHA=cccccccccccccccccccccccccccccccccccccccc
expect_failure retention_continuity_begin "$review"
expect_failure retention_continuity_admit_deployment "$workdir"
EXPECTED_WORKFLOW_SHA=$saved_workflow
((cases+=1))

fixture changed-review
retention_continuity_begin "$review"
printf '\n' >> "$review"
expect_failure retention_continuity_check
status_is HOLD
((cases+=1))

fixture no-lock
exec 9>&-
expect_failure retention_continuity_begin "$review"
[[ ! -e "$workdir/.codex-v2-release/image-cleanup-gate.json" ]]
((cases+=1))

fixture admission-missing
retention_continuity_admit_deployment "$workdir"
printf '{"status":"COMPLETE"}\n' > "$workdir/fake-gate"
chmod 600 "$workdir/fake-gate"
ln -s "$workdir/fake-gate" "$workdir/.codex-v2-release/image-cleanup-gate.json"
expect_failure retention_continuity_admit_deployment "$workdir"
rm -- "$workdir/.codex-v2-release/image-cleanup-gate.json"
cp -- "$workdir/fake-gate" "$workdir/.codex-v2-release/image-cleanup-gate.json"
expect_failure retention_continuity_admit_deployment "$workdir"
((cases+=1))

fixture concurrent-hold
retention_continuity_begin "$review"
declare -a sample_pids=()
for ((i=0;i<5;i++)); do
  (_rc_sample >/dev/null 2>&1 || true) & sample_pids+=("$!")
done
retention_continuity_hold concurrent_failure
for pid in "${sample_pids[@]}"; do wait "$pid"; done
status_is HOLD
expect_failure _rc_sample
status_is HOLD
((cases+=1))

fixture post-verification-failure
retention_continuity_begin "$review"
retention_continuity_intent "$test_image"
retention_continuity_observe "$test_image"
retention_continuity_complete
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" failure
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" failure
status_is HOLD
jq -e '.cleanup_validated==true and .observation_completed_at_uptime!=null' "$RC_GATE" >/dev/null
expect_failure retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$(printf 'f%.0s' {1..64})"
expect_failure retention_continuity_admit_deployment "$workdir"
((cases+=1))

fixture live-sources
RC_REVIEW=$review
_rc_paths "$workdir"
RC_LIVE_SERIES=''
expect_failure _rc_live_signals "$RC_TEST_NOW"
make_signal() {
  jq -n --slurpfile review "$review" --argjson now "$RC_TEST_NOW" '
    $review[0] as $r | {schema_version:1,active_release_sha:$r.active_release_sha,
      protection_fingerprint:$r.protection_fingerprint,source_review_sha256:$r.monitoring.live_signals.source_review_sha256,
      sampled_at_uptime:$now,source_healthy:true,websocket_connected:true,websocket_disconnects_total:0,
      natural_requests_total:100,natural_5xx_total:0,series_id:"isolated-fixture-series-01"}' > "$RC_SIGNALS"
  chmod 600 "$RC_SIGNALS"
}
make_signal
_rc_live_signals "$RC_TEST_NOW"
for signal_change in '.websocket_connected=null' 'del(.natural_5xx_total)' '.source_healthy=false' \
  '.sampled_at_uptime-=21' '.websocket_disconnects_total=1' '.natural_5xx_total=1' \
  '.natural_requests_total=99' '.series_id="another-fixture-series-01"'; do
  make_signal
  jq "$signal_change" "$RC_SIGNALS" > "$workdir/changed-signal"
  chmod 600 "$workdir/changed-signal"
  mv -- "$workdir/changed-signal" "$RC_SIGNALS"
  expect_failure _rc_live_signals "$RC_TEST_NOW"
  ((cases+=1))
done
((cases+=1))

# Exercise the real HTTP validator with an isolated curl boundary. It must not
# accept a 200 response with wrong content or silently allow slow responses.
fixture http-boundary
mkdir "$workdir/bin"
cat > "$workdir/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
output=''
while (($#)); do
  case "$1" in --output) output=$2; shift 2 ;; *) shift ;; esac
done
[[ -n "$output" ]]
printf '%s\n' "${RC_HTTP_TEST_BODY:-fixture}" > "$output"
printf '%s %s' "${RC_HTTP_TEST_STATUS:-200}" "${RC_HTTP_TEST_ELAPSED:-0.01}"
exit "${RC_HTTP_TEST_EXIT:-0}"
CURL
chmod +x "$workdir/bin/curl"
export PATH="$workdir/bin:$PATH"
http_hash=$(printf 'fixture\n' | sha256sum | cut -d' ' -f1)
jq --arg hash "$http_hash" '.monitoring.public.body_sha256=$hash' "$review" > "$workdir/http-review.json"
RC_REVIEW="$workdir/http-review.json"
_rc_paths "$workdir"
_rc_http public
export RC_HTTP_TEST_BODY=unexpected
expect_failure _rc_http public
unset RC_HTTP_TEST_BODY
export RC_HTTP_TEST_STATUS=503
expect_failure _rc_http public
unset RC_HTTP_TEST_STATUS
export RC_HTTP_TEST_ELAPSED=2.1
expect_failure _rc_http public
unset RC_HTTP_TEST_ELAPSED
export RC_HTTP_TEST_EXIT=28
expect_failure _rc_http public
unset RC_HTTP_TEST_EXIT
((cases+=5))

# Keep a real-clock boundary test: fake clocks must not conceal a timeout that
# is shorter than an ordinary wait, or a monitor which cannot terminate.
real_wait_started=$SECONDS
timeout --kill-after=1s 9s bash -c 'source "$1"; _rc_wait 5' _ "$script_dir/retention-continuity.sh"
((SECONDS-real_wait_started>=5))
timeout --kill-after=1s 15s bash -c '
  set -Eeuo pipefail
  source "$1"
  marker=$2
  _rc_sample() { printf "sample\n" >> "$marker"; }
  RC_INTERVAL=5
  _rc_start_observer
  observer_pid=$RC_OBSERVER_PID
  for ((attempt=0;attempt<20;attempt++)); do
    [[ ! -f "$marker" ]] || break
    sleep 0.05
  done
  [[ -f "$marker" ]]
  _rc_stop_observer
  ! kill -0 "$observer_pid" 2>/dev/null
' _ "$script_dir/retention-continuity.sh" "$test_root/real-observer-sample"
((cases+=2))

echo "RETENTION_CONTINUITY_TEST=PASS cases=$cases fixture_clock=true production_connections=0"
