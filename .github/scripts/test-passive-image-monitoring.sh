#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# Synthetic contract/state-machine coverage only. No Docker daemon, network,
# production host, live signal producer, or application request is used here.
# Keep the real probe dispatcher and real Caddy/container identity comparators.
source "$script_dir/retention-continuity.sh"
source "$script_dir/images-only-approval.sh"
for required in jq sha256sum timeout flock mktemp; do command -v "$required" >/dev/null; done

test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
export EXPECTED_WORKFLOW_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export EXPECTED_ACTIVE_RELEASE_ID=123-1
export EXPECTED_ACTIVE_RELEASE_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
export EXPECTED_RETENTION_REVIEW_SHA256=''
export XBOARD_ACTIVE_PORT=7002
test_image="sha256:$(printf 'c%.0s' {1..64})"
other_image="sha256:$(printf 'e%.0s' {1..64})"
evidence_hash=$(printf 'f%.0s' {1..64})
cases=0

fail() { echo "PASSIVE_IMAGE_MONITORING_TEST=FAIL $*" >&2; exit 1; }
expect_failure() {
  if "$@" > "$workdir/failure.log" 2>&1; then fail "unexpected_success:$*"; fi
}
status_is() { jq -e --arg status "$1" '.status==$status' "$RC_GATE" >/dev/null; }
hash_file() { sha256sum "$1" | cut -d' ' -f1; }
bind_review() { EXPECTED_RETENTION_REVIEW_SHA256=$(hash_file "$review"); }

write_review() {
  local mode=$1 destination=$2
  jq -n --arg mode "$mode" --arg active "$EXPECTED_ACTIVE_RELEASE_SHA" --arg image "$test_image" '
    ("d"*64) as $h | {
      schema_version:3,status:"approved",operation:"image_cleanup",active_release_id:"123-1",
      active_release_sha:$active,resource_fingerprint:$h,protection_fingerprint:$h,
      protected_image_revisions:[$active],
      protected_image_digests:[("ghcr.io/hao-monster/xboardme@sha256:"+("b"*64))],
      review_evidence:{scope_review_sha256:$h,isolated_docker_review_sha256:$h},
      audit_completed_at_epoch:1800000000,approval_expires_at_epoch:1800000600,
      max_image_size_bytes:536870912,containers:[],
      images:[{id:$image,revision:("c"*40),snapshot_sha256:$h,
        restore_digest:("ghcr.io/hao-monster/xboardme@sha256:"+("c"*64)),
        evidence:{startup_reference_review_sha256:$h}}],
      monitoring:{mode:"passive_runtime_v1",active_port:7002,
        baseline_seconds:120,post_delete_observe_seconds:300,sample_interval_seconds:10,
        heartbeat_max_age_seconds:30,
        caddy:{unit:"caddy.service",invocation_id:("e"*32),main_pid:42,active_enter_monotonic_usec:1000000},
        protected_containers:[range(1;9)|{id:((tostring)*64),status:"running",
          started_at:"2026-10-12T00:00:00Z",restart_count:0}],
        resources:{max_cpu_busy_percent:85,max_cpu_iowait_percent:10,min_mem_available_kib:131072,
          max_io_full_avg10:1,max_cpu_some_avg10:10}}
    } | if $mode=="legacy" then
      .schema_version=2 |
      del(.monitoring.mode,.monitoring.active_port,.images[0].restore_digest,.images[0].revision) |
      .review_evidence={backup_review_sha256:$h,monitoring_review_sha256:$h,
        authentication_review_sha256:$h,isolated_docker_review_sha256:$h} |
      .monitoring.baseline_seconds=600 | .monitoring.post_delete_observe_seconds=900 |
      .monitoring.public={url:"https://example.invalid/api/readonly",expected_status:200,
        body_sha256:$h,max_latency_ms:2000} |
      .monitoring.origin={url:"http://127.0.0.1:7002/api/readonly",expected_status:200,
        body_sha256:$h,max_latency_ms:2000} |
      .monitoring.evidence={read_only_endpoints_sha256:$h,production_baseline_sha256:$h,
        long_connections_sha256:$h,natural_traffic_sha256:$h,resource_overhead_sha256:$h} |
      .monitoring.live_signals={transport:"host_json_v1",source_review_sha256:$h,max_age_seconds:20}
    else . end' > "$destination"
}

fixture() {
  workdir="$test_root/$1"
  mkdir -p "$workdir/.codex-v2-release"
  chmod 700 "$workdir/.codex-v2-release"
  exec 9>"$workdir/.codex-v2-release/deploy.lock"
  chmod 600 "$workdir/.codex-v2-release/deploy.lock"
  flock -n 9
  review="$workdir/review.json"
  write_review "${2:-passive}" "$review"
  bind_review
  probe_log="$workdir/probes.log"
  : > "$probe_log"
  jq -c '.monitoring.protected_containers[] + {oom_killed:false,health:"healthy"}' \
    "$review" > "$workdir/container-snapshot.jsonl"
  printf 'MainPID=42\nInvocationID=%s\nActiveState=active\nActiveEnterTimestampMonotonic=1000000\n' \
    "$(printf 'e%.0s' {1..32})" > "$workdir/caddy-properties"
  RC_TEST_NOW=1000
  RC_TEST_RESOURCE_FAILURE=''
  RC_EXPECT_LEGACY=false
  [[ "${2:-passive}" != legacy ]] || RC_EXPECT_LEGACY=true
  RC_ARMED=false
  RC_OBSERVER_PID=''
}

# Replace only external boundaries and the clock; never replace _rc_probe.
# The command allowlist makes unexpected network/auth/exec work a test failure.
_rc_run() {
  case "$1" in
    docker)
      printf 'containers\n' >> "$probe_log"
      [[ "$2" == inspect && "$3" == --format && $# -eq 12 ]] || return 1
      cat -- "$workdir/container-snapshot.jsonl"
      ;;
    systemctl)
      printf 'caddy\n' >> "$probe_log"
      [[ "$2" == show && "$3" == caddy.service ]] || return 1
      cat -- "$workdir/caddy-properties"
      ;;
    jq|sha256sum|stat|realpath|readlink|flock|mktemp|chmod|rm|mv|cp|sleep)
      timeout --signal=TERM --kill-after=1s 3s "$@"
      ;;
    *) printf 'forbidden-command:%s\n' "$1" >> "$probe_log"; return 1 ;;
  esac
}
_rc_now() { printf '%s\n' "$RC_TEST_NOW"; }
_rc_wait() { RC_TEST_NOW=$((RC_TEST_NOW+10)); _rc_sample >/dev/null; }
_rc_start_observer() { _rc_sample >/dev/null; }
_rc_stop_observer() { :; }
_rc_image_absent() { :; }
_rc_resources() {
  printf 'resources\n' >> "$probe_log"
  [[ -z "$RC_TEST_RESOURCE_FAILURE" ]] || return 1
  RC_MEMORY_AVAILABLE_KIB=262144 RC_IO_FULL_AVG10=0.00 RC_CPU_SOME_AVG10=0.00
}
_rc_http() {
  printf 'http:%s\n' "$1" >> "$probe_log"
  [[ "$RC_EXPECT_LEGACY" == true ]]
}
_rc_live_signals() {
  printf 'live-signals\n' >> "$probe_log"
  [[ "$RC_EXPECT_LEGACY" == true ]]
}
assert_passive_probes() {
  jq -Rse 'split("\n")|map(select(length>0)) as $calls |
    all($calls[];.=="containers" or .=="caddy" or .=="resources") and
    (["containers","caddy","resources"]|all(.[];. as $kind|$calls|index($kind)!=null))' \
    "$probe_log" >/dev/null || fail non_passive_or_missing_probe
  [[ ! -e "$workdir/.codex-v2-release/image-cleanup-live-signals.json" ]] || fail signal_producer_created
}
complete_observation() {
  retention_continuity_begin "$review"
  retention_continuity_intent "$test_image"
  retention_continuity_observe "$test_image"
  retention_continuity_complete
}

fixture schema
retention_monitoring_validate "$review" || fail schema3_passive_monitoring_rejected
retention_continuity_validate_review "$review" || fail schema3_passive_review_rejected
retention_images_review_validate "$review" || fail schema3_passive_approval_rejected
((cases+=1))

for change in 'del(.monitoring.mode)' '.monitoring.mode=null' '.monitoring.mode=3' \
  '.monitoring.mode="unknown"' '.monitoring.active_port=0' '.monitoring.active_port=65536' \
  '.monitoring.active_port="7002"' '.monitoring.baseline_seconds=119' \
  '.monitoring.post_delete_observe_seconds=299' '.monitoring.sample_interval_seconds=4' \
  '.monitoring.sample_interval_seconds=11' '.monitoring.heartbeat_max_age_seconds=31' \
  '.monitoring.public=null' '.monitoring.origin={}' '.monitoring.live_signals={}' \
  '.monitoring.evidence={}' 'del(.monitoring.caddy)' '.monitoring.caddy.main_pid=null' \
  '.monitoring.protected_containers=[]' '.monitoring.protected_containers+=.monitoring.protected_containers[0:1]' \
  '.monitoring.resources.max_cpu_busy_percent=86' '.monitoring.resources.max_cpu_iowait_percent=11' \
  '.monitoring.resources.min_mem_available_kib=131071' '.monitoring.resources.max_io_full_avg10=1.01' \
  '.monitoring.resources.max_cpu_some_avg10=10.01' 'del(.monitoring.resources.min_mem_available_kib)' \
  '.schema_version=2' '.schema_version=4'; do
  jq "$change" "$review" > "$workdir/invalid.json"
  expect_failure retention_continuity_validate_review "$workdir/invalid.json"
  ((cases+=1))
done

for change in 'del(.review_evidence.scope_review_sha256)' \
  '.review_evidence.scope_review_sha256=("0"*64)' \
  'del(.review_evidence.isolated_docker_review_sha256)' \
  '.review_evidence.backup_review_sha256=("f"*64)' \
  '.review_evidence.monitoring_review_sha256=("f"*64)' \
  '.review_evidence.authentication_review_sha256=("f"*64)' \
  'del(.images[0].revision)' '.images[0].revision="c078add"' \
  'del(.images[0].restore_digest)' '.images[0].restore_digest="ghcr.io/hao-monster/xboardme:old"' \
  '.images[0].restore_digest=("example.invalid/xboardme@sha256:"+("f"*64))'; do
  jq "$change" "$review" > "$workdir/invalid.json"
  expect_failure retention_images_review_validate "$workdir/invalid.json"
  ((cases+=1))
done

# The passive inventory covers every retained container, including historical
# exited entries. Capacity is bounded without assuming the old 16-item limit.
jq '.monitoring.protected_containers[0] as $base |
  .monitoring.protected_containers=[range(1;129)|$base+{id:(("0"*60)+("000"+tostring)[-4:])}]' \
  "$review" > "$workdir/128-containers.json"
retention_monitoring_validate "$workdir/128-containers.json"
jq '.monitoring.protected_containers[0] as $base |
  .monitoring.protected_containers+=[$base+{id:(("0"*60)+"0129")}]' \
  "$workdir/128-containers.json" > "$workdir/129-containers.json"
expect_failure retention_monitoring_validate "$workdir/129-containers.json"
jq '.monitoring.resources={max_cpu_busy_percent:80,max_cpu_iowait_percent:8,
  min_mem_available_kib:262144,max_io_full_avg10:0.5,max_cpu_some_avg10:5}' \
  "$review" > "$workdir/stricter-resources.json"
retention_monitoring_validate "$workdir/stricter-resources.json"
((cases+=3))

fixture legacy-schema legacy
retention_monitoring_validate "$review"
retention_continuity_validate_review "$review"
retention_images_review_validate "$review"
for change in '.monitoring.baseline_seconds=120' '.monitoring.post_delete_observe_seconds=300' \
  'del(.monitoring.public)' 'del(.monitoring.live_signals)' 'del(.monitoring.evidence)' \
  '.monitoring.mode="passive_runtime_v1"' '.monitoring.mode=null'; do
  jq "$change" "$review" > "$workdir/invalid.json"
  expect_failure retention_continuity_validate_review "$workdir/invalid.json"
  ((cases+=1))
done
((cases+=1))

fixture happy
complete_observation
status_is COMPLETE
jq -e '.schema_version==2 and .monitoring_mode=="passive_runtime_v1" and
  .baseline_seconds==120 and .observe_seconds==300 and .sample_interval_seconds==10 and
  .baseline_completed_at_uptime-.baseline_started_at_uptime>=120 and
  .observation_completed_at_uptime-.removed_at_uptime>=300 and
  .sample_count>=42 and .cleanup_validated==true and .post_verification=="pending"' \
  "$RC_GATE" >/dev/null
assert_passive_probes
expect_failure retention_continuity_admit_deployment "$workdir"
expect_failure retention_continuity_begin "$review"
((cases+=1))

saved_workflow=$EXPECTED_WORKFLOW_SHA
EXPECTED_WORKFLOW_SHA=cccccccccccccccccccccccccccccccccccccccc
expect_failure retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$evidence_hash"
EXPECTED_WORKFLOW_SHA=$saved_workflow
expect_failure retention_continuity_postverify "$workdir" "$evidence_hash" success "$evidence_hash"
jq -e '.post_verification=="pending"' "$RC_GATE" >/dev/null
((cases+=1))
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$evidence_hash"
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$evidence_hash"
retention_continuity_admit_deployment "$workdir"
((cases+=1))

# Schema downgrade or mode relabelling cannot turn a shorter observation into
# a legacy admission certificate or authorize a new monitoring contract.
cp -- "$RC_GATE" "$workdir/valid-gate.json"
for change in '.schema_version=1' '.monitoring_mode="unknown"' 'del(.monitoring_mode)'; do
  jq "$change" "$workdir/valid-gate.json" > "$workdir/invalid-gate.json"
  chmod 600 "$workdir/invalid-gate.json"
  expect_failure _rc_gate_validate "$workdir/invalid-gate.json"
  ((cases+=1))
done

for binding in port release-id release-sha; do
  fixture "binding-$binding"
  case "$binding" in
    port) XBOARD_ACTIVE_PORT=7003 ;;
    release-id) EXPECTED_ACTIVE_RELEASE_ID=999-1 ;;
    release-sha) EXPECTED_ACTIVE_RELEASE_SHA=ffffffffffffffffffffffffffffffffffffffff ;;
  esac
  expect_failure retention_continuity_begin "$review"
  [[ ! -e "$workdir/.codex-v2-release/image-cleanup-gate.json" ]]
  XBOARD_ACTIVE_PORT=7002
  EXPECTED_ACTIVE_RELEASE_ID=123-1
  EXPECTED_ACTIVE_RELEASE_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
  ((cases+=1))
done

for fault in restart started status oom health missing duplicate caddy-pid caddy-invocation caddy-start caddy-inactive resources; do
  fixture "metadata-$fault"
  retention_continuity_begin "$review"
  case "$fault" in
    restart|started|status|oom|health|missing|duplicate)
      case "$fault" in
        restart) change='.[0].restart_count+=1' ;;
        started) change='.[0].started_at="2026-10-12T00:00:01Z"' ;;
        status) change='.[0].status="exited"' ;;
        oom) change='.[0].oom_killed=true' ;;
        health) change='.[0].health="unhealthy"' ;;
        missing) change='.[1:]' ;;
        duplicate) change='. + .[0:1]' ;;
      esac
      jq -sc "$change | .[]" "$workdir/container-snapshot.jsonl" > "$workdir/changed-snapshot"
      mv -- "$workdir/changed-snapshot" "$workdir/container-snapshot.jsonl"
      ;;
    caddy-pid) printf 'MainPID=43\n' >> "$workdir/caddy-properties" ;;
    caddy-invocation) printf 'InvocationID=%s\n' "$(printf 'f%.0s' {1..32})" >> "$workdir/caddy-properties" ;;
    caddy-start) printf 'ActiveEnterTimestampMonotonic=1000001\n' >> "$workdir/caddy-properties" ;;
    caddy-inactive) printf 'ActiveState=inactive\n' >> "$workdir/caddy-properties" ;;
    resources) RC_TEST_RESOURCE_FAILURE=synthetic_resource_limit_or_missing_source ;;
  esac
  expect_failure _rc_sample
  status_is HOLD
  expect_failure retention_continuity_admit_deployment "$workdir"
  assert_passive_probes
  ((cases+=1))
done

fixture retained-exited
jq '.monitoring.protected_containers[0].status="exited"' "$review" > "$workdir/exited-review.json"
review="$workdir/exited-review.json"
bind_review
jq -cs '.[0].status="exited" | .[0].health="unhealthy" | .[0].oom_killed=true | .[]' \
  "$workdir/container-snapshot.jsonl" > "$workdir/exited-snapshot"
mv -- "$workdir/exited-snapshot" "$workdir/container-snapshot.jsonl"
retention_continuity_begin "$review"
retention_continuity_check
assert_passive_probes
((cases+=1))
jq -cs '.[0].status="running" | .[0].started_at="2026-10-12T00:00:01Z" | .[]' \
  "$workdir/container-snapshot.jsonl" > "$workdir/unexpectedly-started-snapshot"
mv -- "$workdir/unexpectedly-started-snapshot" "$workdir/container-snapshot.jsonl"
expect_failure _rc_sample
status_is HOLD
expect_failure retention_continuity_admit_deployment "$workdir"
((cases+=1))

fixture heartbeat
retention_continuity_begin "$review"
RC_TEST_NOW=$((RC_TEST_NOW+31))
expect_failure retention_continuity_check
status_is HOLD
expect_failure retention_continuity_admit_deployment "$workdir"
((cases+=1))

fixture changed-mode
retention_continuity_begin "$review"
jq '.monitoring.mode="another_mode"' "$review" > "$workdir/replaced-review.json"
mv -- "$workdir/replaced-review.json" "$review"
expect_failure retention_continuity_check
status_is HOLD
((cases+=1))

fixture postverify-failure
complete_observation
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" failure
status_is HOLD
jq -e '.cleanup_validated==true and .observation_completed_at_uptime!=null and
  .post_verification=="failed"' "$RC_GATE" >/dev/null
expect_failure retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$evidence_hash"
expect_failure retention_continuity_admit_deployment "$workdir"
((cases+=1))

# Every old gate is inspected before admission of a new schema/mode or SHA.
# A new workflow cannot convert RUNNING/HOLD/COMPLETE-pending into permission.
for previous_mode in legacy passive; do
  for previous_status in RUNNING HOLD COMPLETE-pending; do
    fixture "cross-mode-$previous_mode-$previous_status" "$previous_mode"
    retention_continuity_begin "$review"
    case "$previous_status" in
      HOLD) retention_continuity_hold synthetic_existing_hold ;;
      COMPLETE-pending)
        retention_continuity_intent "$test_image"
        retention_continuity_observe "$test_image"
        retention_continuity_complete
        ;;
    esac
    original_gate_hash=$(hash_file "$RC_GATE")
    EXPECTED_WORKFLOW_SHA=cccccccccccccccccccccccccccccccccccccccc
    next_mode=passive
    [[ "$previous_mode" != passive ]] || next_mode=legacy
    write_review "$next_mode" "$workdir/new-review.json"
    review="$workdir/new-review.json"
    bind_review
    expect_failure retention_continuity_begin "$review"
    expect_failure retention_continuity_admit_deployment "$workdir"
    [[ "$(hash_file "$RC_GATE")" == "$original_gate_hash" ]] || fail old_gate_was_overwritten
    EXPECTED_WORKFLOW_SHA=$saved_workflow
    ((cases+=1))
  done
done

# Legacy success remains readable. Only a fresh, separately bound single-image
# approval can rotate that evidence; the exact prior gate must be archived.
fixture legacy-complete legacy
complete_observation
jq -e '.schema_version==1 and .baseline_seconds==600 and .observe_seconds==900' "$RC_GATE" >/dev/null
retention_continuity_postverify "$workdir" "$EXPECTED_RETENTION_REVIEW_SHA256" success "$evidence_hash"
retention_continuity_admit_deployment "$workdir"
jq -Rse 'split("\n") as $calls|["http:public","http:origin","live-signals"]|
  all(.[];. as $kind|$calls|index($kind)!=null)' "$probe_log" >/dev/null
old_session=$(jq -r .session_id "$RC_GATE")
original_gate_hash=$(hash_file "$RC_GATE")
write_review passive "$workdir/passive-review.json"
jq --arg image "$other_image" '.images[0].id=$image' "$workdir/passive-review.json" > "$workdir/next-review.json"
review="$workdir/next-review.json"
bind_review
RC_EXPECT_LEGACY=false
: > "$probe_log"
retention_continuity_begin "$review"
jq -e '.schema_version==2 and .monitoring_mode=="passive_runtime_v1" and .status=="RUNNING"' "$RC_GATE" >/dev/null
[[ "$(hash_file "$RC_ROOT/image-cleanup-gate-history-$old_session.json")" == "$original_gate_hash" ]]
assert_passive_probes
retention_continuity_hold synthetic_test_finished
((cases+=1))

printf 'PASSIVE_IMAGE_MONITORING_TEST=PASS cases=%s fixture_clock=true docker_daemon_connections=0 production_connections=0\n' "$cases"
