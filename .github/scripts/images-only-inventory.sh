#!/usr/bin/env bash
# Image-only inventory. Source after retention-safety.sh; shared helpers remain
# unchanged. Fixed workers never detach; only read-only Docker metadata is used.

_images_only_now_ms() {
  local seconds unused fraction
  read -r seconds unused < /proc/uptime || return 1
  [[ $seconds =~ ^[0-9]{1,10}\.[0-9]{1,6}$ ]] || return 1
  fraction=${seconds#*.}000; fraction=${fraction:0:3}
  printf '%s\n' "$((10#${seconds%%.*}*1000+10#$fraction))"
}

_images_only_stop_group() {
  local pid=$1 attempt
  [[ $pid =~ ^[1-9][0-9]*$ && $pid -gt 1 && $pid != "$BASHPID" ]] || return 1
  # Cancellation may land immediately after fork, before timeout has created
  # its group. The directly owned child PID must be stopped in that window too.
  if ! kill -0 -- "-$pid" 2>/dev/null && ! kill -0 "$pid" 2>/dev/null; then return 0; fi
  kill -TERM "$pid" 2>/dev/null || true
  kill -TERM -- "-$pid" 2>/dev/null || true
  for ((attempt=0;attempt<10;attempt++)); do
    if ! kill -0 -- "-$pid" 2>/dev/null && ! kill -0 "$pid" 2>/dev/null; then return 0; fi
    sleep 0.05
  done
  kill -KILL "$pid" 2>/dev/null || true
  kill -KILL -- "-$pid" 2>/dev/null || true
  for ((attempt=0;attempt<10;attempt++)); do
    if ! kill -0 -- "-$pid" 2>/dev/null && ! kill -0 "$pid" 2>/dev/null; then return 0; fi
    sleep 0.05
  done
  ! kill -0 -- "-$pid" 2>/dev/null && ! kill -0 "$pid" 2>/dev/null
}

# Shared by production and direct watchdog tests. The caller owns a private
# worker file. Production passes its original start and a fixed 120000ms budget.
# Charge setup to the same deadline and reserve four seconds for TERM/KILL,
# group reap and validation. These are software bounds, not kernel guarantees.
# Never apply --foreground to this OUTER timeout: it must own the entire group.
_images_only_supervise() {
  # A regular function is deliberate: when launched with &, $! must be the
  # shell that owns these traps, not a wrapper around another subshell body.
  # This internal primitive must run in an owned child shell (the public
  # entry launches it in the background; direct tests use an explicit child).
  [[ $BASHPID != $$ ]] || return 1
  set +x
  set -Eeuo pipefail
  set +m
  local root=$1 start=$2 budget=$3 pid='' status=0 size now remaining duration launching=false interrupted=0
  [[ $start =~ ^[0-9]{1,15}$ && $budget =~ ^[0-9]{1,6}$ && -d $root && ! -L $root &&
     -f $root/worker && ! -L $root/worker ]] || return 1
  ((budget>4000 && budget<=120000)) || return 1
  _iis_exit() {
    # A parent and a group cancellation can arrive together. Repeated signals
    # must not kill this owner halfway through stopping its separate group.
    trap '' HUP INT TERM
    local result=$1
    trap - EXIT
    if [[ -n $pid ]]; then
      _images_only_stop_group "$pid" >/dev/null 2>&1 || result=1
      wait "$pid" 2>/dev/null || true
    fi
    exit "$result"
  }
  trap '_iis_exit "$?"' EXIT
  # Ignore repeats in the signal handler itself, not only later in EXIT: a
  # second TERM can otherwise re-enter exit before cleanup has begun. If fork
  # is in progress, defer exit until the exact child PID has been registered.
  trap 'trap "" HUP INT TERM; if [[ $launching == true ]]; then interrupted=129; else exit 129; fi' HUP
  trap 'trap "" HUP INT TERM; if [[ $launching == true ]]; then interrupted=130; else exit 130; fi' INT
  trap 'trap "" HUP INT TERM; if [[ $launching == true ]]; then interrupted=143; else exit 143; fi' TERM
  now=$(_images_only_now_ms) || exit 1
  ((now>=start)) || exit 1
  remaining=$((start+budget-now-4000))
  ((remaining>0)) || exit 1
  printf -v duration '%d.%03ds' "$((remaining/1000))" "$((remaining%1000))"
  launching=true
  BASH_ENV=/dev/null ENV=/dev/null command timeout --signal=TERM --kill-after=2s "$duration" \
    bash --noprofile --norc "$root/worker" "$root" > "$root/wire" 2>/dev/null &
  pid=$!
  launching=false
  ((interrupted==0)) || exit "$interrupted"
  if wait "$pid" 2>/dev/null; then :; else status=$?; fi
  printf '%s\n' "$status" > "$root/worker-status" || exit 1
  if kill -0 -- "-$pid" 2>/dev/null; then
    exit 1
  fi
  pid=''
  ((status==0)) || exit 1
  size=$(stat -c %s -- "$root/wire") || exit 1
  [[ $size =~ ^[0-9]+$ ]] && ((size<=16777216)) || exit 1
  # Reject NUL before Bash or JSON consumers can normalize it away.
  local nul
  if IFS= read -r -d '' nul < "$root/wire"; then exit 1; fi
  now=$(_images_only_now_ms) || exit 1
  ((now>=start && now<start+budget)) || exit 1
  cat -- "$root/wire" || exit 1
  # Exit while locals still exist so EXIT cleanup can read the owned pid.
  exit 0
}

_images_only_append_container() {
  local root=$1 next total
  next=$(stat -c %s -- "$root/container-snapshot") || return 1
  total=$(stat -c %s -- "$root/containers") || return 1
  [[ $next =~ ^[0-9]+$ && $total =~ ^[0-9]+$ ]] || return 1
  ((next<=1048576 && total+next<=16777216)) || return 1
  cat -- "$root/container-snapshot" >> "$root/containers"
}

# Only fixed phase names and the supervisor's numeric wait status may leave the
# private directory. Raw Docker/jq stderr remains discarded, including secrets.
_images_only_failure_diagnostic() {
  local root=$1 phase=unavailable status=unavailable value bytes nul file
  file="$root/phase"
  if [[ -f $file && ! -L $file ]] && bytes=$(stat -c %s -- "$file") &&
     [[ $bytes =~ ^[0-9]+$ ]] && ((bytes>0 && bytes<=64)); then
    if IFS= read -r -d '' nul < "$file"; then :; else
      value=$(< "$file")
      if ((bytes==${#value}+1)); then
        case "$value" in
          container_list|container_snapshot|image_list|image_inspect|image_protocol|image_relist|image_aggregate|final_aggregate|complete)
            phase=$value ;;
        esac
      fi
    fi
  fi
  file="$root/worker-status"
  if [[ -f $file && ! -L $file ]] && bytes=$(stat -c %s -- "$file") &&
     [[ $bytes =~ ^[0-9]+$ ]] && ((bytes>0 && bytes<=4)); then
    if IFS= read -r -d '' nul < "$file"; then :; else
      value=$(< "$file")
      if [[ $value =~ ^(0|[1-9][0-9]{0,2})$ ]] && ((value<=255 && bytes==${#value}+1)); then
        status=$value
      fi
    fi
  fi
  printf 'IMAGE_INVENTORY_DIAGNOSTIC=phase:%s worker_exit:%s\n' "$phase" "$status" >&2
}

images_only_inventory_worker() {
  local root=$1 ids id
  # The unchanged container helper resolves this local adapter. Inner metadata
  # timeouts remain in the one outer group; no nested timeout process groups.
  docker() (
    local bytes nul
    if { command timeout --foreground --signal=TERM --kill-after=2s 10s docker "$@" |
         command head -c 1048577 > "$root/container-read"; } 2>/dev/null; then :; else return 1; fi
    bytes=$(stat -c %s -- "$root/container-read") || return 1
    [[ $bytes =~ ^[0-9]+$ ]] && ((bytes<=1048576)) || return 1
    if IFS= read -r -d '' nul < "$root/container-read"; then return 1; fi
    cat -- "$root/container-read"
  )
  printf 'container_list\n' > "$root/phase" || return 1
  ids=$(docker ps -aq --no-trunc | command sort -u) || return 1
  : > "$root/containers" || return 1
  printf 'container_snapshot\n' > "$root/phase" || return 1
  while IFS= read -r id; do
    [[ -n $id ]] || continue
    [[ $id =~ ^[a-f0-9]{64}$ ]] || return 1
    # Bound both one canonical record and the aggregate before appending. Never
    # truncate inventory: an oversized resource makes the whole read fail closed.
    retention_container_snapshot "$id" | command head -c 1048577 > "$root/container-snapshot" || return 1
    _images_only_append_container "$root" || return 1
  done <<< "$ids"
  images_only_images_snapshot_worker "$root" > "$root/images" || return 1
  printf 'final_aggregate\n' > "$root/phase" || return 1
  jq -cnS --slurpfile containers "$root/containers" --slurpfile images "$root/images" \
    '{containers:($containers|sort_by(.id)),images:$images[0]}' || return 1
  printf 'complete\n' > "$root/phase"
}

# Synchronous public entry: preserve the caller's traps and shell options.
# External cancellation targets the caller's process group or this actual
# body, not a wrapper PID from asynchronously invoking a subshell function.
images_only_all_snapshots() (
  set +x
  set -Eeuo pipefail
  set +m
  umask 077
  local root='' supervisor='' tool code=1 start end result launching=false interrupted=0
  start=$(_images_only_now_ms) || return 1
  for tool in bash cat chmod docker head jq mktemp rm rmdir sha256sum sort stat timeout; do
    command -v "$tool" >/dev/null || { echo 'IMAGE_INVENTORY_FAIL=dependency' >&2; return 1; }
  done
  declare -F retention_hash retention_container_snapshot >/dev/null || return 1
  root=$(mktemp -d) || return 1
  _iia_exit() {
    trap '' HUP INT TERM
    local result=$1
    trap - EXIT
    # The owned supervisor alone knows its independent timeout group. Forward
    # cancellation and wait for that group's cleanup before removing its files.
    if [[ -n $supervisor ]]; then
      kill -TERM "$supervisor" 2>/dev/null || true
      wait "$supervisor" 2>/dev/null || true
    fi
    # Exact generated filenames only; never recursive cleanup or unknown data.
    rm -f -- "$root/worker" "$root/wire" "$root/accepted" "$root/containers" "$root/images" \
      "$root/list" "$root/raw" "$root/batch" "$root/batches" "$root/final" \
      "$root/container-read" "$root/container-snapshot" "$root/phase" "$root/worker-status" 2>/dev/null || result=1
    rmdir -- "$root" 2>/dev/null || result=1
    exit "$result"
  }
  trap '_iia_exit "$?"' EXIT
  trap 'trap "" HUP INT TERM; if [[ $launching == true ]]; then interrupted=129; else exit 129; fi' HUP
  trap 'trap "" HUP INT TERM; if [[ $launching == true ]]; then interrupted=130; else exit 130; fi' INT
  trap 'trap "" HUP INT TERM; if [[ $launching == true ]]; then interrupted=143; else exit 143; fi' TERM
  {
    printf '%s\n' 'set +x' 'set -Eeuo pipefail' 'set +m' 'umask 077'
    declare -f retention_hash retention_container_snapshot _images_only_append_container images_only_inventory_worker images_only_images_snapshot_worker
    printf '%s\n' 'images_only_inventory_worker "$1" | command head -c 16777217'
  } > "$root/worker" || return 1
  chmod 600 "$root/worker" || return 1
  launching=true
  _images_only_supervise "$root" "$start" 120000 > "$root/accepted" &
  supervisor=$!
  launching=false
  ((interrupted==0)) || exit "$interrupted"
  if wait "$supervisor"; then
    result=$(< "$root/accepted")
    end=$(_images_only_now_ms) || return 1
    if ((end>=start && end-start<=120000)); then code=0; fi
  fi
  supervisor=''
  if ((code!=0)); then
    _images_only_failure_diagnostic "$root"
    echo 'IMAGE_INVENTORY_FAIL=bounded_snapshot' >&2
  fi
  ((code!=0)) || printf '%s\n' "$result"
  return "$code"
)

images_only_images_snapshot_worker() (
  set +x
  set -Eeuo pipefail
  export LC_ALL=C
  umask 077
  local root=$1 temp=$1 start deadline now duration output size
  local ids='' after='' id expected='' all_expected='' batches='' result='' position
  local -a selected=() chunk=()
  _rib_fail() { printf 'IMAGE_INVENTORY_WORKER_FAIL=%s\n' "$1" >&2; return 1; }
  # Fixed fields only. Explicit guards handle absent/null Config and Labels.
  local format='{"Id":{{json .Id}},"Size":{{json .Size}},"Created":{{json .Created}},"revision":{{if .Config}}{{if .Config.Labels}}{{json (index .Config.Labels "org.opencontainers.image.revision")}}{{else}}null{{end}}{{else}}null{{end}},"source":{{if .Config}}{{if .Config.Labels}}{{json (index .Config.Labels "org.opencontainers.image.source")}}{{else}}null{{end}}{{else}}null{{end}},"RepoTags":{{json .RepoTags}},"RepoDigests":{{json .RepoDigests}}}'
  _rib_now() {
    local seconds fraction unused clock=/proc/uptime
    read -r seconds unused < "$clock" || return 1
    [[ "$seconds" =~ ^[0-9]{1,10}\.[0-9]{1,6}$ ]] || return 1
    fraction=${seconds#*.}000; fraction=${fraction:0:3}
    now=$((10#${seconds%%.*}*1000+10#$fraction))
  }
  _rib_budget() {
    local maximum=$1 remaining
    _rib_now || return 1
    ((now>=start && now<deadline)) || return 1
    remaining=$((deadline-now-2000))
    ((remaining>0)) || return 1
    ((remaining<=maximum)) || remaining=$maximum
    printf -v duration '%d.%03ds' "$((remaining/1000))" "$((remaining%1000))"
  }
  _rib_check_deadline() { _rib_now && ((now>=start && now<=deadline)); }
  _rib_capture() {
    local cap=$1 maximum=$2 destination=$3 bytes
    shift 3
    _rib_budget "$maximum" || return 1
    # Both sides are bounded; upstream failure is never hidden by head success.
    if { command timeout --foreground --signal=TERM --kill-after=2s "$duration" "$@" 2>/dev/null |
       command head -c "$((cap+1))" > "$destination"; } 2>/dev/null; then :; else return 1; fi
    bytes=$(stat -c %s -- "$destination") || return 1
    [[ "$bytes" =~ ^[0-9]+$ ]] && ((bytes<=cap)) || return 1
    # Bash cannot faithfully hold NULs. Reject before any command substitution.
    if IFS= read -r -d '' output < "$destination"; then return 1; fi
    _rib_check_deadline
  }
  _rib_list() {
    local row collected='' count=0
    local -A seen=()
    _rib_capture 131072 10000 "$temp/list" docker image ls -aq --no-trunc || return 1
    while IFS= read -r row || [[ -n "$row" ]]; do
      [[ "$row" =~ ^sha256:[a-f0-9]{64}$ ]] || return 1
      if [[ ! ${seen[$row]+present} ]]; then
        seen[$row]=1
        count=$((count+1)); ((count<=256)) || return 1
        collected+="$row"$'\n'
      fi
    done < "$temp/list"
    # Full IDs are ASCII and bounded; order is not authority.
    _rib_budget 5000 || return 1
    ids=$(printf '%s' "$collected" | command timeout --foreground --signal=TERM --kill-after=2s "$duration" sort) || return 1
    _rib_check_deadline
  }
  _rib_now || { _rib_fail clock; exit 1; }
  start=$now; deadline=$((start+120000))
  : > "$temp/batches" || { _rib_fail temporary_write; exit 1; }
  printf 'image_list\n' > "$root/phase" || exit 1
  _rib_list || { _rib_fail listing; exit 1; }
  if [[ -n "$ids" ]]; then
    mapfile -t selected <<< "$ids" || { _rib_fail listing_parse; exit 1; }
  fi
  local original_ids=$ids
  all_expected='['
  for id in "${selected[@]}"; do all_expected+="\"$id\","; done
  all_expected=${all_expected%,}']'
  for ((position=0;position<${#selected[@]};position+=16)); do
    chunk=("${selected[@]:position:16}")
    expected='['
    for id in "${chunk[@]}"; do expected+="\"$id\","; done
    expected=${expected%,}']'
    printf 'image_inspect\n' > "$root/phase" || exit 1
    _rib_capture 1048576 10000 "$temp/raw" docker image inspect --format "$format" "${chunk[@]}" || {
      _rib_fail inspect; exit 1;
    }
    _rib_budget 5000 || { _rib_fail deadline; exit 1; }
    printf 'image_protocol\n' > "$root/phase" || exit 1
    # Streaming paths retain duplicate-key evidence that ordinary fromjson loses.
    # Every object is rebuilt only after rejecting duplicate/unknown/nested keys.
    if command timeout --foreground --signal=TERM --kill-after=2s "$duration" jq --stream -nceS --argjson expected "$expected" '
      def keys_ok: ["Created","Id","RepoDigests","RepoTags","Size","revision","source"];
      def invalid: error("invalid_protocol");
      reduce inputs as $e ({rows:[],object:{},seen:{}};
        if ($e|length)==2 then
          $e[0] as $p | $e[1] as $v |
          if ($p|length)==1 and ($p[0]|type)=="string" and (keys_ok|index($p[0]))!=null then
            if .seen[$p[0]] then invalid else .seen[$p[0]]=true | .object[$p[0]]=$v end
          elif ($p|length)==2 and ($p[0]=="RepoTags" or $p[0]=="RepoDigests") and
             ($p[1]|type)=="number" and ($v|type)=="string" then
            if $p[1]==0 then
              if .seen[$p[0]] then invalid else .seen[$p[0]]=true | .object[$p[0]]=[] end
            else . end |
            if (.object[$p[0]]|type)=="array" and (.object[$p[0]]|length)==$p[1]
            then .object[$p[0]]+=[$v] else invalid end
          else invalid end
        elif ($e|length)==1 and ($e[0]|length)==2 and ($e[0][0]=="RepoTags" or $e[0][0]=="RepoDigests") then .
        elif ($e|length)==1 and ($e[0]|length)==1 then
          if (.object|keys)==keys_ok and (.rows|length)<16 then
            .rows+=[.object] | .object={} | .seen={}
          else invalid end
        else invalid end) |
      if .object!={} or .seen!={} then invalid else .rows end |
      if length!=($expected|length) then invalid else . end |
      if all(.[];
        (.Id|type)=="string" and (.Id|test("^sha256:[a-f0-9]{64}$")) and
        (.Size|type)=="number" and .Size>=0 and .Size<=9007199254740991 and (.Size|floor)==.Size and
        (.Created|type)=="string" and
        (.revision==null or (.revision|type)=="string") and (.source==null or (.source|type)=="string") and
        (.RepoTags==null or ((.RepoTags|type)=="array" and all(.RepoTags[];type=="string"))) and
        (.RepoDigests==null or ((.RepoDigests|type)=="array" and all(.RepoDigests[];type=="string"))))
      then . else invalid end |
      if ([.[].Id]|sort)==($expected|sort) and ([.[].Id]|unique|length)==length then . else invalid end |
      map({id:.Id,size_bytes:.Size,created_at:.Created,revision:(.revision//"none"),source:(.source//"none"),
        tags:((.RepoTags//[])|sort),digests:((.RepoDigests//[])|sort)}) | sort_by(.id)
    ' "$temp/raw" > "$temp/batch" 2>/dev/null; then :; else _rib_fail protocol; exit 1; fi
    _rib_check_deadline || { _rib_fail deadline; exit 1; }
    # Safe accepted batch bytes remain private until all batches and re-list pass.
    while IFS= read -r output; do
      printf '%s\n' "$output" >> "$temp/batches" || { _rib_fail temporary_write; exit 1; }
    done < "$temp/batch" || { _rib_fail temporary_read; exit 1; }
  done
  printf 'image_relist\n' > "$root/phase" || exit 1
  _rib_list || { _rib_fail relisting; exit 1; }
  [[ "$ids" == "$original_ids" ]] || { _rib_fail inventory_drift; exit 1; }
  _rib_budget 5000 || { _rib_fail deadline; exit 1; }
  printf 'image_aggregate\n' > "$root/phase" || exit 1
  if command timeout --foreground --signal=TERM --kill-after=2s "$duration" jq -cesS --argjson expected "$all_expected" '
    (add//[])|sort_by(.id)|
    if [.[].id]==($expected|sort) and ([.[].id]|unique|length)==length then . else error("invalid_final_inventory") end
  ' "$temp/batches" > "$temp/final" 2>/dev/null; then :; else
    _rib_fail aggregation; exit 1;
  fi
  result=$(< "$temp/final") || { _rib_fail temporary_read; exit 1; }
  _rib_check_deadline || { _rib_fail deadline; exit 1; }
  printf '%s\n' "$result"
)
