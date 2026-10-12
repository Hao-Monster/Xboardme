#!/usr/bin/env bash
# Source-only safety module. No work, host access or background process starts
# on source. Callers must already own the production deploy.lock through fd 9.
# Test clocks/probes are overridden in isolated tests, never via production env.

_rc_run() { timeout --signal=TERM --kill-after=1s 3s "$@"; }
_rc_jq() { _rc_run jq "$@"; }
_rc_hash() { local result; result=$(_rc_run sha256sum) || return 1; printf '%s\n' "${result%% *}"; }
_rc_now() { local uptime unused; read -r uptime unused < /proc/uptime || return 1; printf '%s\n' "${uptime%%.*}"; }
_rc_wait() {
  [[ "$1" =~ ^[1-9]$|^10$ ]] || return 1
  timeout --signal=TERM --kill-after=1s 12s sleep "$1"
}
_rc_fail() { printf 'RETENTION_CONTINUITY_FAIL=%s\n' "$1" >&2; return 1; }

# Pure schema validation for runner admission. Schema 2 retains its original
# traffic/connection contract. Schema 3 is a separately reviewed image-only
# contract: runtime identity and resource observations, not connection proof.
retention_monitoring_validate() {
  _rc_jq -e '
    def integer: type=="number" and floor==.;
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    def positive: integer and .>0;
    def endpoint: .expected_status==200 and (.body_sha256|hash) and
      (.max_latency_ms|positive and .<=2000);
    . as $review | .monitoring as $m |
    ($m|type=="object") and $m.heartbeat_max_age_seconds==30 and
    $m.caddy.unit=="caddy.service" and
    ($m.caddy.invocation_id|type=="string" and test("^[a-f0-9]{32}$") and .!=("0"*32)) and
    ($m.caddy.main_pid|positive) and ($m.caddy.active_enter_monotonic_usec|positive) and
    ($m.protected_containers|type=="array" and length>=6 and
      length<=(if $review.schema_version==3 then 128 else 16 end) and
      all(.[]; (.id|hash) and (.status=="running" or .status=="exited") and
        (.started_at|type=="string" and test("^[0-9]{4}-[0-9T:Z.+-]+$")) and
        (.restart_count|integer and .>=0))) and
    ([$m.protected_containers[].id]|length==(unique|length)) and
    ($m.resources.max_cpu_busy_percent|positive and .<=95) and
    ($m.resources.max_cpu_iowait_percent|positive and .<=20) and
    ($m.resources.min_mem_available_kib|positive and .>=65536) and
    ($m.resources.max_io_full_avg10|type=="number" and .>0 and .<=5) and
    ($m.resources.max_cpu_some_avg10|type=="number" and .>0 and .<=20) and
    (if .schema_version==2 then
      ($m|has("mode")|not) and ($m|has("active_port")|not) and
      ($m.baseline_seconds|integer and .>=600 and .<=1800) and
      ($m.post_delete_observe_seconds|integer and .>=900 and .<=1800) and
      ($m.sample_interval_seconds|integer and .>=5 and .<=10) and
      ($m.public|endpoint) and ($m.origin|endpoint) and
      ($m.public.url|type=="string" and test("^https://[A-Za-z0-9][A-Za-z0-9.-]*(:[0-9]{1,5})?/[A-Za-z0-9_./~-]*$")) and
      ($m.origin.url|type=="string" and test("^http://127\\.0\\.0\\.1:[0-9]{1,5}/[A-Za-z0-9_./~-]*$")) and
      ($m.evidence.read_only_endpoints_sha256|hash) and
      ($m.evidence.production_baseline_sha256|hash) and
      ($m.evidence.long_connections_sha256|hash) and
      ($m.evidence.natural_traffic_sha256|hash) and
      ($m.evidence.resource_overhead_sha256|hash) and
      $m.live_signals.transport=="host_json_v1" and
      ($m.live_signals.source_review_sha256|hash) and
      $m.live_signals.max_age_seconds==20
    elif .schema_version==3 then
      $m.mode=="passive_runtime_v1" and
      ($m.active_port|integer and .>=1 and .<=65535) and
      $m.baseline_seconds==120 and $m.post_delete_observe_seconds==300 and
      $m.sample_interval_seconds==10 and
      ($m|has("public") or has("origin") or has("live_signals") or has("evidence")|not) and
      $m.resources.max_cpu_busy_percent<=85 and
      $m.resources.max_cpu_iowait_percent<=10 and
      $m.resources.min_mem_available_kib>=131072 and
      $m.resources.max_io_full_avg10<=1 and $m.resources.max_cpu_some_avg10<=10
    else false end)
  ' "$1" >/dev/null
}

retention_continuity_validate_review() {
  retention_monitoring_validate "$1" || return 1
  _rc_jq -e '
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    (.schema_version==2 or .schema_version==3) and .status=="approved" and .operation=="image_cleanup" and
    (.active_release_id|type=="string" and test("^[0-9]+-[0-9]+$")) and
    (.active_release_sha|type=="string" and test("^[a-f0-9]{40}$")) and
    (.protection_fingerprint|hash) and (.resource_fingerprint|hash) and
    .containers==[] and (.images|type=="array" and length==1) and
    (.images[0].id|type=="string" and test("^sha256:[a-f0-9]{64}$"))
  ' "$1" >/dev/null
}

_rc_secure_file() {
  local metadata
  [[ -f "$1" && ! -L "$1" ]] || return 1
  metadata=$(_rc_run stat -Lc '%u:%a:%h' -- "$1") || return 1
  [[ "$metadata" == "$EUID:600:1" ]]
}

_rc_paths() {
  local root=${1:?workdir required} canonical metadata
  [[ "$root" == /* && -d "$root" && ! -L "$root" ]] || return 1
  canonical=$(_rc_run realpath -e -- "$root") || return 1
  [[ "$canonical" == "$root" ]] || return 1
  RC_ROOT="$root/.codex-v2-release"
  [[ -d "$RC_ROOT" && ! -L "$RC_ROOT" ]] || return 1
  [[ "$(_rc_run realpath -e -- "$RC_ROOT")" == "$RC_ROOT" ]] || return 1
  metadata=$(_rc_run stat -Lc '%u:%a' -- "$RC_ROOT") || return 1
  [[ "$metadata" == "$EUID:700" ]] || return 1
  RC_GATE="$RC_ROOT/image-cleanup-gate.json"
  RC_GATE_LOCK="$RC_ROOT/image-cleanup-gate.lock"
  RC_SIGNALS="$RC_ROOT/image-cleanup-live-signals.json"
}

_rc_assert_deploy_lock() {
  local fd_target
  _rc_secure_file "$RC_ROOT/deploy.lock" || return 1
  fd_target=$(_rc_run readlink -- "/proc/$BASHPID/fd/9") || return 1
  [[ "$fd_target" == "$RC_ROOT/deploy.lock" ]] || return 1
  _rc_run flock -n 9
}

_rc_gate_validate() {
  _rc_secure_file "$1" || return 1
  _rc_jq -e --argjson owner "$EUID" '
    def integer: type=="number" and floor==.;
    def clock: integer and .>=0;
    def hash: type=="string" and test("^[a-f0-9]{64}$") and .!=("0"*64);
    (if .schema_version==1 then
      (has("monitoring_mode")|not) and
      (.baseline_seconds|integer and .>=600) and (.observe_seconds|integer and .>=900) and
      (.sample_interval_seconds|integer and .>=5 and .<=10)
    elif .schema_version==2 then
      .monitoring_mode=="passive_runtime_v1" and .baseline_seconds==120 and
      .observe_seconds==300 and .sample_interval_seconds==10
    else false end) and
    (.status=="RUNNING" or .status=="HOLD" or .status=="COMPLETE") and
    .owner_uid==$owner and
    (.session_id|type=="string" and test("^[a-f0-9-]{36}$")) and
    (.workflow_sha|type=="string" and test("^[a-f0-9]{40}$")) and
    (.active_release_sha|type=="string" and test("^[a-f0-9]{40}$")) and
    (.active_release_id|type=="string" and test("^[0-9]+-[0-9]+$")) and
    (.review_sha256|hash) and (.protection_fingerprint|hash) and
    (.image_id|type=="string" and test("^sha256:[a-f0-9]{64}$")) and
    (.started_at_uptime|clock) and (.sample_count|integer and .>=0) and
    (.post_verification=="pending" or .post_verification=="passed" or .post_verification=="failed") and
    (.post_verification!="passed" or (
      .status=="COMPLETE" and (.post_evidence_sha256|hash) and (.post_verified_at_uptime|clock) and
      .post_verified_at_uptime>=.completed_at_uptime)) and
    (.status!="COMPLETE" or (
      .failure_reason==null and .cleanup_validated==true and
      (.baseline_started_at_uptime|clock) and (.baseline_completed_at_uptime|clock) and
      (.intent_at_uptime|clock) and (.removed_at_uptime|clock) and
      (.observation_completed_at_uptime|clock) and (.completed_at_uptime|clock) and
      .baseline_started_at_uptime>=.started_at_uptime and
      .baseline_completed_at_uptime-.baseline_started_at_uptime>=.baseline_seconds and
      .intent_at_uptime>=.baseline_completed_at_uptime and .removed_at_uptime>=.intent_at_uptime and
      .observation_completed_at_uptime-.removed_at_uptime>=.observe_seconds and
      .completed_at_uptime>=.observation_completed_at_uptime and
      (.last_heartbeat_uptime|clock) and .completed_at_uptime-.last_heartbeat_uptime<=30 and
      .completed_at_uptime>=.last_heartbeat_uptime and
      .sample_count>=((.baseline_seconds+.observe_seconds)/.sample_interval_seconds|floor)))
  ' "$1" >/dev/null
}

# Every update re-reads under a separate short gate lock. The background
# observer never upgrades/releases deploy.lock and cannot overwrite HOLD.
_rc_update() (
  local filter=$1 temporary
  shift
  _rc_secure_file "$RC_GATE_LOCK" || return 1
  exec 8>"$RC_GATE_LOCK" || return 1
  _rc_run flock -w 2 8 || return 1
  _rc_gate_validate "$RC_GATE" || return 1
  _rc_jq -e --arg session "$RC_SESSION" '.session_id==$session' "$RC_GATE" >/dev/null || return 1
  temporary=$(_rc_run mktemp "$RC_ROOT/.continuity-state.XXXXXX") || return 1
  trap '_rc_run rm -f -- "$temporary"' EXIT
  _rc_run chmod 600 "$temporary" || return 1
  _rc_jq "$@" "$filter" "$RC_GATE" > "$temporary" || return 1
  _rc_gate_validate "$temporary" || return 1
  _rc_run mv -f -- "$temporary" "$RC_GATE"
)

# A new reviewed single-image operation may follow a fully validated operation.
# Archive before replacing, never reset a RUNNING/HOLD or reuse an old approval.
_rc_create_gate() (
  local temporary=$1 old_session old_hash history archive_tmp=''
  _rc_secure_file "$RC_GATE_LOCK" || return 1
  exec 8>"$RC_GATE_LOCK" || return 1
  _rc_run flock -w 2 8 || return 1
  if [[ -e "$RC_GATE" || -L "$RC_GATE" ]]; then
    _rc_gate_validate "$RC_GATE" || return 1
    _rc_jq -e --slurpfile fresh "$temporary" '
      .status=="COMPLETE" and .cleanup_validated==true and .post_verification=="passed" and
      .review_sha256!=$fresh[0].review_sha256 and .image_id!=$fresh[0].image_id and
      .session_id!=$fresh[0].session_id' "$RC_GATE" >/dev/null || return 1
    old_session=$(_rc_jq -r .session_id "$RC_GATE") || return 1
    old_hash=$(_rc_hash < "$RC_GATE") || return 1
    history="$RC_ROOT/image-cleanup-gate-history-$old_session.json"
    if [[ -e "$history" || -L "$history" ]]; then
      _rc_secure_file "$history" && [[ "$(_rc_hash < "$history")" == "$old_hash" ]] || return 1
    else
      archive_tmp=$(_rc_run mktemp "$RC_ROOT/.continuity-history.XXXXXX") || return 1
      trap '[[ -z "$archive_tmp" ]] || _rc_run rm -f -- "$archive_tmp"' EXIT
      _rc_run cp -- "$RC_GATE" "$archive_tmp" && _rc_run chmod 600 "$archive_tmp" || return 1
      _rc_gate_validate "$archive_tmp" || return 1
      _rc_run mv -n -- "$archive_tmp" "$history" || return 1
      _rc_secure_file "$history" && [[ "$(_rc_hash < "$history")" == "$old_hash" ]] || return 1
    fi
  fi
  _rc_gate_validate "$temporary" && _rc_run mv -f -- "$temporary" "$RC_GATE"
)

retention_continuity_hold() {
  local reason=${1:-unspecified}
  [[ "$reason" =~ ^[a-zA-Z0-9_:-]{1,120}$ ]] || reason=invalid_failure_reason
  [[ "${RC_ARMED:-false}" == true ]] || return 0
  _rc_update 'if .status=="COMPLETE" then error("already complete")
    elif .status=="HOLD" then . else .status="HOLD" | .failure_reason=$reason | .cleanup_validated=false end' \
    --arg reason "$reason" || { _rc_fail hold_persistence_failed; return 1; }
  printf 'RETENTION_CONTINUITY=HOLD reason=%s\n' "$reason" >&2
}

_rc_reject() { retention_continuity_hold "$1" || true; _rc_fail "$1"; }

# Read-only deployment admission. A future workflow SHA must not bypass an old
# failure. COMPLETE is evidence of this cleanup only, not an automatic release.
retention_continuity_admit_deployment() (
  _rc_paths "$1" || { _rc_fail unsafe_gate_path; return 1; }
  [[ ! -e "$RC_GATE" && ! -L "$RC_GATE" ]] && return 0
  _rc_gate_validate "$RC_GATE" || { _rc_fail invalid_persistent_gate; return 1; }
  _rc_jq -e '.status=="COMPLETE" and .cleanup_validated==true and .post_verification=="passed"' "$RC_GATE" >/dev/null || {
    _rc_fail cleanup_running_or_held; return 1;
  }
)

_rc_http() {
  local name=$1 url expected latency response body actual elapsed
  url=$(_rc_jq -r --arg name "$name" '.monitoring[$name].url' "$RC_REVIEW") || return 1
  expected=$(_rc_jq -r --arg name "$name" '.monitoring[$name].body_sha256' "$RC_REVIEW") || return 1
  latency=$(_rc_jq -r --arg name "$name" '.monitoring[$name].max_latency_ms' "$RC_REVIEW") || return 1
  body=$(_rc_run mktemp "$RC_ROOT/.continuity-body.XXXXXX") || return 1
  _rc_run chmod 600 "$body" || return 1
  # No curlrc, proxy, credential, redirect, retry or unbounded response body.
  response=$(_rc_run curl --disable --noproxy '*' --silent --show-error \
    --connect-timeout 1 --max-time 2 --max-filesize 65536 --proto '=http,https' \
    --header 'Cache-Control: no-cache' --output "$body" --write-out '%{http_code} %{time_total}' "$url") || {
      _rc_run rm -f -- "$body"; return 1;
    }
  read -r actual elapsed <<< "$response"
  [[ "$actual" == 200 && "$(_rc_hash < "$body")" == "$expected" ]] || {
    _rc_run rm -f -- "$body"; return 1;
  }
  _rc_run rm -f -- "$body" || return 1
  _rc_jq -en --arg elapsed "$elapsed" --argjson limit "$latency" \
    '($elapsed|tonumber)*1000<=$limit' >/dev/null || return 1
  printf -v "RC_HTTP_${name^^}_SECONDS" '%s' "$elapsed"
}

# Call only from the protected post-verification workflow, under deploy.lock.
# A successful mutator leaves admission closed until independent verification
# succeeds. Failure/cancellation may set HOLD even after observation completed.
retention_continuity_postverify() (
  local target_workdir=$1 review_hash=$2 outcome=$3 evidence_hash=${4:-} now
  RC_ARMED=false
  _rc_paths "$target_workdir" && _rc_assert_deploy_lock && _rc_gate_validate "$RC_GATE" || {
    _rc_fail post_verification_context_invalid; return 1;
  }
  [[ "$review_hash" =~ ^[a-f0-9]{64}$ && "${EXPECTED_WORKFLOW_SHA:-}" =~ ^[a-f0-9]{40}$ ]] || return 1
  _rc_jq -e --arg hash "$review_hash" --arg workflow "$EXPECTED_WORKFLOW_SHA" \
    '.review_sha256==$hash and .workflow_sha==$workflow' "$RC_GATE" >/dev/null || {
      _rc_fail post_verification_identity_changed; return 1;
    }
  RC_SESSION=$(_rc_jq -r .session_id "$RC_GATE") || return 1
  now=$(_rc_now) || return 1
  case "$outcome" in
    success)
      [[ "$evidence_hash" =~ ^[a-f0-9]{64}$ && "$evidence_hash" != "$(printf '0%.0s' {1..64})" ]] || return 1
      _rc_update 'if .status=="COMPLETE" and .post_verification=="passed" and .post_evidence_sha256==$evidence then .
        elif .status!="COMPLETE" or .post_verification!="pending" then error("not awaiting verification")
        else .post_verification="passed" | .post_evidence_sha256=$evidence | .post_verified_at_uptime=$now end' \
        --arg evidence "$evidence_hash" --argjson now "$now" || return 1
      ;;
    failure|cancelled)
      _rc_update 'if .status=="HOLD" then . else .status="HOLD" |
        .post_verification="failed" | .failure_reason="independent_post_verification_failed" end' || return 1
      ;;
    *) _rc_fail invalid_post_verification_result; return 1 ;;
  esac
  printf 'RETENTION_CONTINUITY_POST_VERIFICATION=%s\n' "$outcome"
)

_rc_containers() {
  local ids snapshot
  ids=$(_rc_jq -r '.monitoring.protected_containers[].id' "$RC_REVIEW") || return 1
  local -a selected=()
  mapfile -t selected <<< "$ids"
  snapshot=$(_rc_run docker inspect --format \
    '{"id":{{json .Id}},"status":{{json .State.Status}},"started_at":{{json .State.StartedAt}},"restart_count":{{json .RestartCount}},"oom_killed":{{json .State.OOMKilled}},"health":{{if eq (index .State "Health") nil}}"none"{{else}}{{json .State.Health.Status}}{{end}}}' \
    "${selected[@]}") || return 1
  # Docker itself selects secret-free fields. Never copy Config.Env into argv,
  # logs or an observation artifact merely to check process identity.
  # Health is absent without a healthcheck. Use index for that optional key;
  # present but malformed health data must still fail the strict template.
  _rc_jq -se --slurpfile review "$RC_REVIEW" '
    . as $actual | [$actual[]|{id,status,started_at,restart_count}]|sort_by(.id) as $live |
    $live==($review[0].monitoring.protected_containers|sort_by(.id)) and
    all($actual[];
      # Stopped historical containers retain stale health/OOM fields. Their
      # exact lifecycle remains frozen; they are never started to test health.
      if $review[0].schema_version==3 and .status=="exited" then true
      else .oom_killed==false and (.health=="none" or .health=="healthy") end)' <<< "$snapshot" >/dev/null
}

_rc_caddy() {
  local actual key value pid='' invocation='' active='' since=''
  actual=$(_rc_run systemctl show caddy.service --property=MainPID --property=InvocationID \
    --property=ActiveState --property=ActiveEnterTimestampMonotonic) || return 1
  while IFS='=' read -r key value; do
    case "$key" in MainPID) pid=$value ;; InvocationID) invocation=$value ;;
      ActiveState) active=$value ;; ActiveEnterTimestampMonotonic) since=$value ;; esac
  done <<< "$actual"
  [[ "$active" == active ]] || return 1
  _rc_jq -e --arg pid "$pid" --arg invocation "$invocation" --arg since "$since" '
    .monitoring.caddy.main_pid==($pid|tonumber) and .monitoring.caddy.invocation_id==$invocation and
    .monitoring.caddy.active_enter_monotonic_usec==($since|tonumber)' "$RC_REVIEW" >/dev/null
}

# These six resource-only functions are also serialized by the independent
# diagnostic transport. Keep their dependency closure free of lifecycle/gate
# functions, arbitrary paths, files written on the host and external probes.
_rc_resource_read() {
  case "$1" in
    stat) _rc_run head -n 1 /proc/stat ;;
    memory) _rc_run head -c 65536 /proc/meminfo ;;
    io) _rc_run head -c 4096 /proc/pressure/io ;;
    cpu) _rc_run head -c 4096 /proc/pressure/cpu ;;
    *) return 2 ;;
  esac
}

_rc_resource_error() {
  RC_RESOURCE_REASON=$1
  printf 'RETENTION_RESOURCE_ERROR=%s source=%s exit_code=%s\n' "$1" "$2" "$3" >&2
  return 1
}

_rc_resource_limits_valid() {
  # Preserve the legacy contract. The diagnostic admission additionally enforces
  # the stricter passive limits from the fixed, committed schema-3 review.
  _rc_jq -ne --argjson limits "$1" '
    def positive: type=="number" and .>0;
    $limits | type=="object" and
    keys==["max_cpu_busy_percent","max_cpu_iowait_percent","max_cpu_some_avg10","max_io_full_avg10","min_mem_available_kib"] and
    (.max_cpu_busy_percent|positive and floor==. and .<=95) and
    (.max_cpu_iowait_percent|positive and floor==. and .<=20) and
    (.min_mem_available_kib|positive and floor==. and .>=65536 and .<=9007199254740991) and
    (.max_io_full_avg10|positive and .<=5) and
    (.max_cpu_some_avg10|positive and .<=20)' >/dev/null
}

_rc_resource_sample() {
  local limits=${1:?resource limits required} snapshot status field
  local label user nice system idle iowait irq softirq steal unused total busy
  local key value unit rest memory='' io='' cpu='' matches
  local delta=null busy_delta=null wait_delta=null phase=initial comparison outcome
  RC_RESOURCE_REASON=''
  RC_MEMORY_AVAILABLE_KIB='' RC_IO_FULL_AVG10='' RC_CPU_SOME_AVG10=''
  RC_CPU_BUSY_PERCENT='' RC_CPU_IOWAIT_PERCENT=''
  if _rc_resource_limits_valid "$limits"; then :; else
    status=$?; _rc_resource_error invalid_limits limits "$status"; return 1
  fi
  if snapshot=$(_rc_resource_read stat); then :; else
    status=$?; _rc_resource_error proc_read_failed stat "$status"; return 1
  fi
  read -r label user nice system idle iowait irq softirq steal unused <<< "${snapshot%%$'\n'*}"
  [[ "$label" == cpu ]] || { _rc_resource_error invalid_cpu_stat stat 1; return 1; }
  # Check each field: an all-whitespace concatenation previously admitted
  # missing fields as zero. The bound also prevents shell-integer overflow.
  for field in "$user" "$nice" "$system" "$idle" "$iowait" "$irq" "$softirq" "$steal"; do
    [[ "$field" =~ ^[0-9]{1,15}$ ]] || { _rc_resource_error invalid_cpu_stat stat 1; return 1; }
  done
  total=$((10#$user+10#$nice+10#$system+10#$idle+10#$iowait+10#$irq+10#$softirq+10#$steal))
  iowait=$((10#$iowait)); busy=$((total-10#$idle-iowait))
  if snapshot=$(_rc_resource_read memory); then :; else
    status=$?; _rc_resource_error proc_read_failed memory "$status"; return 1
  fi
  matches=0
  while read -r key value unit rest; do
    if [[ "$key" == MemAvailable: ]]; then
      matches=$((matches+1)); memory=$value
      [[ "$unit" == kB && -z "$rest" ]] || { _rc_resource_error invalid_mem_available memory 1; return 1; }
    fi
  done <<< "$snapshot"
  [[ "$matches" == 1 && "$memory" =~ ^[0-9]{1,15}$ ]] || { _rc_resource_error invalid_mem_available memory 1; return 1; }
  memory=$((10#$memory))
  if snapshot=$(_rc_resource_read io); then :; else
    status=$?; _rc_resource_error proc_read_failed io "$status"; return 1
  fi
  matches=0
  while read -r label value rest; do
    if [[ "$label" == full ]]; then
      [[ "$value" == avg10=* ]] || { _rc_resource_error invalid_io_pressure io 1; return 1; }
      matches=$((matches+1)); io=${value#avg10=}
    fi
  done <<< "$snapshot"
  [[ "$matches" == 1 && "$io" =~ ^[0-9]{1,3}\.[0-9]{1,6}$ ]] || { _rc_resource_error invalid_io_pressure io 1; return 1; }
  if snapshot=$(_rc_resource_read cpu); then :; else
    status=$?; _rc_resource_error proc_read_failed cpu "$status"; return 1
  fi
  matches=0
  while read -r label value rest; do
    if [[ "$label" == some ]]; then
      [[ "$value" == avg10=* ]] || { _rc_resource_error invalid_cpu_pressure cpu 1; return 1; }
      matches=$((matches+1)); cpu=${value#avg10=}
    fi
  done <<< "$snapshot"
  [[ "$matches" == 1 && "$cpu" =~ ^[0-9]{1,3}\.[0-9]{1,6}$ ]] || { _rc_resource_error invalid_cpu_pressure cpu 1; return 1; }
  if [[ -n "${RC_CPU_TOTAL:-}" ]]; then
    phase=delta
    delta=$((total-RC_CPU_TOTAL)); wait_delta=$((iowait-RC_CPU_IOWAIT)); busy_delta=$((busy-RC_CPU_BUSY))
    ((delta>0 && busy_delta>=0 && wait_delta>=0)) || { _rc_resource_error cpu_counter_regressed stat 1; return 1; }
  fi
  if comparison=$(_rc_jq -nr --argjson limits "$limits" --arg phase "$phase" \
    --argjson memory "$memory" --argjson io "$io" --argjson cpu "$cpu" \
    --argjson delta "$delta" --argjson busy "$busy_delta" --argjson waiting "$wait_delta" '
      (if $delta==null then null else $busy*100/$delta end) as $busy_percent |
      (if $delta==null then null else $waiting*100/$delta end) as $wait_percent |
      [if $memory<$limits.min_mem_available_kib then "min_mem_available_kib" else empty end,
       if $io>$limits.max_io_full_avg10 then "max_io_full_avg10" else empty end,
       if $cpu>$limits.max_cpu_some_avg10 then "max_cpu_some_avg10" else empty end,
       if $busy_percent!=null and $busy_percent>$limits.max_cpu_busy_percent then "max_cpu_busy_percent" else empty end,
       if $wait_percent!=null and $wait_percent>$limits.max_cpu_iowait_percent then "max_cpu_iowait_percent" else empty end] as $violations |
      {schema_version:1,result:(if $violations==[] then "PASS" else "FAIL" end),phase:$phase,
       mem_available_kib:$memory,io_full_avg10:$io,cpu_some_avg10:$cpu,
       cpu_busy_percent:$busy_percent,cpu_iowait_percent:$wait_percent,
       thresholds:$limits,violations:$violations} | .result, tojson'); then :; else
    status=$?; _rc_resource_error comparison_command_failed comparison "$status"; return 1
  fi
  outcome=${comparison%%$'\n'*}
  [[ "$outcome" == PASS || "$outcome" == FAIL ]] || { _rc_resource_error comparison_command_failed comparison 1; return 1; }
  printf 'RETENTION_RESOURCE_SAMPLE=%s\n' "${comparison#*$'\n'}"
  if [[ "$outcome" == FAIL ]]; then RC_RESOURCE_REASON=threshold_exceeded; return 1; fi
  if [[ "$phase" == delta ]]; then
    RC_CPU_BUSY_PERCENT=$((busy_delta*100/delta)); RC_CPU_IOWAIT_PERCENT=$((wait_delta*100/delta))
  fi
  RC_CPU_TOTAL=$total RC_CPU_BUSY=$busy RC_CPU_IOWAIT=$iowait
  RC_MEMORY_AVAILABLE_KIB=$memory RC_IO_FULL_AVG10=$io RC_CPU_SOME_AVG10=$cpu
}

_rc_resources() {
  local limits status
  RC_RESOURCE_REASON=''
  RC_MEMORY_AVAILABLE_KIB='' RC_IO_FULL_AVG10='' RC_CPU_SOME_AVG10=''
  RC_CPU_BUSY_PERCENT='' RC_CPU_IOWAIT_PERCENT=''
  if limits=$(_rc_jq -ce '.monitoring.resources' "$RC_REVIEW"); then :; else
    status=$?; _rc_resource_error invalid_limits limits "$status"; return 1
  fi
  _rc_resource_sample "$limits"
}

# A separately reviewed live producer must atomically refresh this fixed file.
# No producer is bundled implicitly: absent/stale natural-traffic or persistent
# connection evidence is a hard failure, not an inference from HTTP success.
_rc_live_signals() {
  local now=$1 signal counter snapshot sampled requests
  _rc_secure_file "$RC_SIGNALS" || return 1
  snapshot=$(_rc_jq -c . "$RC_SIGNALS") || return 1
  _rc_jq -e --slurpfile review "$RC_REVIEW" --argjson now "$now" '
    def integer: type=="number" and floor==.;
    . as $s | $review[0] as $r |
    $s.schema_version==1 and $s.active_release_sha==$r.active_release_sha and
    $s.protection_fingerprint==$r.protection_fingerprint and
    $s.source_review_sha256==$r.monitoring.live_signals.source_review_sha256 and
    ($s.sampled_at_uptime|integer and .>=0) and $now>=$s.sampled_at_uptime and
    $now-$s.sampled_at_uptime<=$r.monitoring.live_signals.max_age_seconds and
    $s.source_healthy==true and $s.websocket_connected==true and $s.websocket_disconnects_total==0 and
    ($s.natural_requests_total|integer and .>=0) and ($s.natural_5xx_total|integer and .>=0) and
    $s.natural_5xx_total<=$s.natural_requests_total and
    ($s.series_id|type=="string" and test("^[a-zA-Z0-9-]{16,80}$"))' <<< "$snapshot" >/dev/null || return 1
  signal=$(_rc_jq -r .series_id <<< "$snapshot") || return 1
  counter=$(_rc_jq -r .natural_5xx_total <<< "$snapshot") || return 1
  sampled=$(_rc_jq -r .sampled_at_uptime <<< "$snapshot") || return 1
  requests=$(_rc_jq -r .natural_requests_total <<< "$snapshot") || return 1
  if [[ -n "${RC_LIVE_SERIES:-}" ]]; then
    [[ "$signal" == "$RC_LIVE_SERIES" && "$counter" == "$RC_LIVE_ERRORS" ]] &&
      ((sampled>=RC_LIVE_SAMPLE && requests>=RC_LIVE_REQUESTS)) || return 1
  fi
  RC_LIVE_SERIES=$signal RC_LIVE_ERRORS=$counter RC_LIVE_SAMPLE=$sampled RC_LIVE_REQUESTS=$requests
}

_rc_probe() {
  RC_PROBE_FAILURE=''
  case "${RC_MONITORING_MODE:-legacy_live_v1}" in
    legacy_live_v1)
      _rc_http public || { RC_PROBE_FAILURE=public_http_failed; return 1; }
      _rc_http origin || { RC_PROBE_FAILURE=origin_http_failed; return 1; }
      ;;
    passive_runtime_v1) ;;
    *) RC_PROBE_FAILURE=unknown_monitoring_mode; return 1 ;;
  esac
  _rc_containers || { RC_PROBE_FAILURE=protected_runtime_changed; return 1; }
  _rc_caddy || { RC_PROBE_FAILURE=caddy_continuity_changed; return 1; }
  _rc_resources || { RC_PROBE_FAILURE=resource_limit_or_source_failed; return 1; }
  if [[ "${RC_MONITORING_MODE:-legacy_live_v1}" == legacy_live_v1 ]]; then
    _rc_live_signals "$1" || { RC_PROBE_FAILURE=live_traffic_or_connection_source_unverified; return 1; }
  fi
}

_rc_sample() {
  local started now
  started=$(_rc_now) || { _rc_reject clock_unavailable; return 1; }
  _rc_probe "$started" || { _rc_reject "${RC_PROBE_FAILURE:-live_probe_failed}"; return 1; }
  now=$(_rc_now) || { _rc_reject clock_unavailable; return 1; }
  ((now>=started && now-started<=RC_INTERVAL)) || { _rc_reject sampling_overrun; return 1; }
  _rc_update 'if .status!="RUNNING" then error("not running")
    elif .last_heartbeat_uptime!=null and ($now<.last_heartbeat_uptime or $now-.last_heartbeat_uptime>30)
      then error("heartbeat discontinuity")
    else .last_heartbeat_uptime=$now | .sample_count+=1 |
      .baseline_started_at_uptime=(.baseline_started_at_uptime // $now) end' --argjson now "$now" || {
        _rc_reject heartbeat_write_failed; return 1;
      }
  if [[ "${RC_MONITORING_MODE:-legacy_live_v1}" == passive_runtime_v1 ]]; then
    printf 'RETENTION_PASSIVE_SAMPLE=PASS uptime=%s cpu_busy_percent=%s cpu_iowait_percent=%s mem_available_kib=%s io_full_avg10=%s cpu_some_avg10=%s http=not_run connections=not_measured\n' \
      "$now" "${RC_CPU_BUSY_PERCENT:-initial_sample}" "${RC_CPU_IOWAIT_PERCENT:-initial_sample}" \
      "${RC_MEMORY_AVAILABLE_KIB:-unverified}" "${RC_IO_FULL_AVG10:-unverified}" "${RC_CPU_SOME_AVG10:-unverified}"
  else
    printf 'RETENTION_CONTINUITY_SAMPLE=PASS uptime=%s public_seconds=%s origin_seconds=%s mem_available_kib=%s io_full_avg10=%s cpu_some_avg10=%s\n' \
      "$now" "${RC_HTTP_PUBLIC_SECONDS:-unverified}" "${RC_HTTP_ORIGIN_SECONDS:-unverified}" \
      "${RC_MEMORY_AVAILABLE_KIB:-unverified}" "${RC_IO_FULL_AVG10:-unverified}" "${RC_CPU_SOME_AVG10:-unverified}"
  fi
}

_rc_start_observer() {
  local owner_pid=$BASHPID owner_stat owner_start
  local -a owner_fields
  read -r owner_stat < "/proc/$owner_pid/stat" || return 1
  owner_stat=${owner_stat##*) }
  read -r -a owner_fields <<< "$owner_stat"
  owner_start=${owner_fields[19]}
  (
    trap 'exit 0' TERM INT HUP
    while :; do
      local started elapsed delay
      local live_stat
      if ! kill -0 "$owner_pid" 2>/dev/null || ! read -r live_stat < "/proc/$owner_pid/stat"; then
        _rc_reject controller_lost; exit 1
      fi
      live_stat=${live_stat##*) }
      read -r -a owner_fields <<< "$live_stat"
      [[ "${owner_fields[19]}" == "$owner_start" ]] || { _rc_reject controller_replaced; exit 1; }
      started=$(_rc_now) || exit 1
      _rc_sample || exit 1
      elapsed=$(( $(_rc_now)-started ))
      delay=$((RC_INTERVAL-elapsed))
      ((delay>0)) || delay=1
      # sleep itself is bounded, but is not limited by the 3-second command cap.
      timeout --signal=TERM --kill-after=1s 12s sleep "$delay" || exit 1
    done
  ) &
  RC_OBSERVER_PID=$!
}

_rc_stop_observer() {
  if [[ "${RC_OBSERVER_PID:-}" =~ ^[0-9]+$ ]]; then
    kill -TERM "$RC_OBSERVER_PID" 2>/dev/null || true
    # The remote workflow's process-group timeout remains the final boundary.
    # Do not kill Docker, Caddy, or a process selected from untrusted metadata.
    local attempt
    for ((attempt=0;attempt<120;attempt++)); do
      kill -0 "$RC_OBSERVER_PID" 2>/dev/null || break
      _rc_run sleep 0.1 || return 1
    done
    if kill -0 "$RC_OBSERVER_PID" 2>/dev/null; then return 1; fi
    wait "$RC_OBSERVER_PID" 2>/dev/null || true
    RC_OBSERVER_PID=''
  fi
}

retention_continuity_check() {
  local now
  [[ "${RC_ARMED:-false}" == true ]] || { _rc_fail not_started; return 1; }
  _rc_assert_deploy_lock || { _rc_reject deployment_lock_lost; return 1; }
  [[ "$(_rc_hash < "$RC_REVIEW")" == "$RC_REVIEW_HASH" ]] || { _rc_reject review_changed; return 1; }
  now=$(_rc_now) || { _rc_reject clock_unavailable; return 1; }
  _rc_gate_validate "$RC_GATE" && _rc_jq -e --arg session "$RC_SESSION" --argjson now "$now" '
    .session_id==$session and .status=="RUNNING" and .failure_reason==null and
    (.last_heartbeat_uptime|type=="number") and $now>=.last_heartbeat_uptime and
    $now-.last_heartbeat_uptime<=30' "$RC_GATE" >/dev/null || {
      _rc_reject monitor_or_state_unhealthy; return 1;
    }
}

retention_continuity_begin() {
  local review=$1 now session
  # A rejected new invocation must never mutate another invocation's gate.
  RC_ARMED=false
  _rc_paths "${workdir:?workdir required}" || { _rc_fail unsafe_gate_path; return 1; }
  _rc_assert_deploy_lock || { _rc_fail deployment_lock_required; return 1; }
  if [[ -e "$RC_GATE" || -L "$RC_GATE" ]]; then
    _rc_gate_validate "$RC_GATE" && _rc_jq -e '.status=="COMPLETE"' "$RC_GATE" >/dev/null || {
      _rc_fail existing_gate_requires_review; return 1;
    }
  fi
  [[ -f "$review" && ! -L "$review" ]] || { _rc_fail invalid_review_path; return 1; }
  retention_continuity_validate_review "$review" || { _rc_fail invalid_monitoring_review; return 1; }
  [[ "${EXPECTED_WORKFLOW_SHA:-}" =~ ^[a-f0-9]{40}$ && "${EXPECTED_RETENTION_REVIEW_SHA256:-}" =~ ^[a-f0-9]{64}$ ]] || {
    _rc_fail missing_workflow_binding; return 1;
  }
  RC_REVIEW=$review
  RC_REVIEW_HASH=$(_rc_hash < "$review") || return 1
  [[ "$RC_REVIEW_HASH" == "$EXPECTED_RETENTION_REVIEW_SHA256" ]] || { _rc_fail review_hash_mismatch; return 1; }
  # This is derived only from the validated, hash-bound review. There is no
  # production environment or dispatch switch that skips the legacy probes.
  RC_MONITORING_MODE=$(_rc_jq -r 'if .schema_version==3 then .monitoring.mode else "legacy_live_v1" end' "$review") || return 1
  _rc_jq -e --arg id "${EXPECTED_ACTIVE_RELEASE_ID:-}" --arg sha "${EXPECTED_ACTIVE_RELEASE_SHA:-}" \
    --arg port "${XBOARD_ACTIVE_PORT:-}" '
      .active_release_id==$id and .active_release_sha==$sha and
      ($port|test("^[0-9]{1,5}$")) and
      (if .schema_version==3 then .monitoring.active_port==($port|tonumber)
       else (.monitoring.origin.url|startswith("http://127.0.0.1:"+$port+"/")) end)' \
    "$review" >/dev/null || { _rc_fail active_monitoring_binding_mismatch; return 1; }
  RC_INTERVAL=$(_rc_jq -r .monitoring.sample_interval_seconds "$review") || return 1
  RC_BASELINE=$(_rc_jq -r .monitoring.baseline_seconds "$review") || return 1
  RC_OBSERVE=$(_rc_jq -r .monitoring.post_delete_observe_seconds "$review") || return 1
  now=$(_rc_now) || return 1
  read -r session < /proc/sys/kernel/random/uuid || return 1
  RC_SESSION=$session
  RC_CPU_TOTAL='' RC_LIVE_SERIES='' RC_CPU_BUSY_PERCENT='' RC_CPU_IOWAIT_PERCENT=''
  if [[ -e "$RC_GATE_LOCK" || -L "$RC_GATE_LOCK" ]]; then
    _rc_secure_file "$RC_GATE_LOCK" || { _rc_fail unsafe_gate_lock; return 1; }
  else
    (umask 077; set -o noclobber; : > "$RC_GATE_LOCK") || return 1
  fi
  local temporary
  temporary=$(_rc_run mktemp "$RC_ROOT/.continuity-state.XXXXXX") || return 1
  _rc_run chmod 600 "$temporary" || return 1
  _rc_jq -n --slurpfile review "$review" --argjson owner "$EUID" --arg session "$RC_SESSION" \
    --arg workflow "$EXPECTED_WORKFLOW_SHA" --arg hash "$RC_REVIEW_HASH" --argjson now "$now" '
    $review[0] as $r | {schema_version:(if $r.schema_version==3 then 2 else 1 end),status:"RUNNING",owner_uid:$owner,session_id:$session,
      workflow_sha:$workflow,review_sha256:$hash,active_release_id:$r.active_release_id,
      active_release_sha:$r.active_release_sha,protection_fingerprint:$r.protection_fingerprint,image_id:$r.images[0].id,
      baseline_seconds:$r.monitoring.baseline_seconds,observe_seconds:$r.monitoring.post_delete_observe_seconds,
      sample_interval_seconds:$r.monitoring.sample_interval_seconds,started_at_uptime:$now,
      last_heartbeat_uptime:null,baseline_started_at_uptime:null,baseline_completed_at_uptime:null,
      intent_at_uptime:null,removed_at_uptime:null,observation_completed_at_uptime:null,completed_at_uptime:null,
      sample_count:0,cleanup_validated:false,failure_reason:null,post_verification:"pending",
      post_evidence_sha256:null,post_verified_at_uptime:null} |
      if $r.schema_version==3 then .monitoring_mode=$r.monitoring.mode else . end' > "$temporary" || return 1
  if ! _rc_create_gate "$temporary"; then
    _rc_run rm -f -- "$temporary" || true
    _rc_fail new_operation_not_approved
    return 1
  fi
  RC_ARMED=true
  _rc_start_observer || { _rc_reject observer_start_failed; return 1; }
  local baseline_start=''
  while :; do
    # The first bounded sample may still be in flight. No deletion is possible
    # until the complete baseline has subsequently passed.
    baseline_start=$(_rc_jq -r '.baseline_started_at_uptime // empty' "$RC_GATE") || { _rc_reject baseline_state_invalid; return 1; }
    if [[ -n "$baseline_start" ]]; then
      retention_continuity_check || return 1
      now=$(_rc_now) || return 1
      ((now-baseline_start>=RC_BASELINE)) && break
    else
      now=$(_rc_now) || return 1
      ((now- $(_rc_jq -r .started_at_uptime "$RC_GATE") <=30)) || { _rc_reject first_sample_missing; return 1; }
      _rc_jq -e '.status=="RUNNING"' "$RC_GATE" >/dev/null || { _rc_reject baseline_failed; return 1; }
    fi
    _rc_wait 5 || { _rc_reject baseline_wait_failed; return 1; }
  done
  _rc_update 'if .status!="RUNNING" then error("not running") else .baseline_completed_at_uptime=$now end' --argjson now "$now" || return 1
  printf 'RETENTION_CONTINUITY_BASELINE=PASS seconds=%s session=%s\n' "$RC_BASELINE" "$RC_SESSION"
}

retention_continuity_intent() {
  local id=$1 now
  retention_continuity_check || return 1
  now=$(_rc_now) || return 1
  _rc_update 'if .status!="RUNNING" or .image_id!=$id or .intent_at_uptime!=null or .baseline_completed_at_uptime==null
    then error("invalid deletion intent") else .intent_at_uptime=$now end' --arg id "$id" --argjson now "$now" || {
      _rc_reject invalid_deletion_intent; return 1;
    }
}

_rc_image_absent() {
  local ids id
  ids=$(_rc_run docker image ls -aq --no-trunc) || return 1
  while IFS= read -r id; do [[ "$id" != "$1" ]] || return 1; done <<< "$ids"
}

retention_continuity_observe() {
  local id=$1 started now
  retention_continuity_check || return 1
  _rc_image_absent "$id" || { _rc_reject deleted_image_present_or_unknown; return 1; }
  started=$(_rc_now) || return 1
  _rc_update 'if .status!="RUNNING" or .image_id!=$id or .intent_at_uptime==null or .removed_at_uptime!=null
    then error("invalid removal completion") else .removed_at_uptime=$now end' --arg id "$id" --argjson now "$started" || {
      _rc_reject invalid_removal_completion; return 1;
    }
  while :; do
    retention_continuity_check || return 1
    now=$(_rc_now) || return 1
    ((now-started>=RC_OBSERVE)) && break
    _rc_wait 5 || { _rc_reject observation_wait_failed; return 1; }
  done
  _rc_image_absent "$id" || { _rc_reject deleted_image_reappeared_or_unknown; return 1; }
  _rc_update 'if .status!="RUNNING" then error("not running") else .observation_completed_at_uptime=$now end' --argjson now "$now" || return 1
}

retention_continuity_complete() {
  local now
  retention_continuity_check || return 1
  _rc_stop_observer || { _rc_reject observer_stop_unverified; return 1; }
  retention_continuity_check || return 1
  now=$(_rc_now) || return 1
  _rc_update 'if .status!="RUNNING" or .observation_completed_at_uptime==null or .removed_at_uptime==null
    then error("incomplete observation") else .status="COMPLETE" | .completed_at_uptime=$now | .cleanup_validated=true end' \
    --argjson now "$now" || { _rc_reject completion_invalid; return 1; }
  RC_ARMED=false
  echo 'RETENTION_CONTINUITY=OBSERVATION_COMPLETE cleanup_validated=true post_verification=pending automatic_deployment=false'
}
