#!/usr/bin/env bash
set -Eeuo pipefail
# Fake Docker never forwards commands. Production parser/watchdog are invoked
# directly; Go is a test-only template compatibility probe, not a host dependency.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/retention-safety.sh"
source "$script_dir/images-only-inventory.sh"
root=$(mktemp -d "${TMPDIR:-/tmp}/xboard-image-inventory-test.XXXXXXXX")
cleanup() {
  [[ -d $root && ! -L $root && $(realpath -e -- "$root") == "$root" &&
     ${root##*/} == xboard-image-inventory-test.* ]] || return 1
  rm -rf -- "$root"
}
trap cleanup EXIT
mkdir "$root/bin"
cp "$script_dir/fixtures/image-inventory/docker.sh" "$root/bin/docker"
if [[ -n ${IMAGE_INVENTORY_TEST_RENDERER:-} ]]; then
  cp -- "$IMAGE_INVENTORY_TEST_RENDERER" "$root/bin/template-render"
else
  command -v go >/dev/null || { echo 'IMAGE_INVENTORY_TEST=NOT_RUN missing_test_only_go' >&2; exit 2; }
  go build -trimpath -o "$root/bin/template-render" "$script_dir/fixtures/image-inventory/template-render.go"
fi
chmod 700 "$root/bin/docker" "$root/bin/template-render"
export IMAGE_INVENTORY_FIXTURE_ROOT=$root PATH="$root/bin:$PATH"
jq -nc '[range(1;147)|. as $n|{Id:("sha256:"+(("0"*64)+($n|tostring))[-64:]),Size:4096,Created:"2020-01-01T00:00:00Z",
  Config:{Env:["SYNTHETIC_ENV_SECRET_CANARY"],Labels:{"unrelated.secret":"SYNTHETIC_LABEL_SECRET_CANARY"}},
  RepoTags:[("test:image-"+($n|tostring))],RepoDigests:[]}]' > "$root/raw-base.json"
jq -nc '[range(1;25)|{Id:(("0"*64)+(tostring))[-64:],Name:("/created-"+tostring),
  Image:("sha256:"+("0"*63)+"1"),Config:{Env:["SYNTHETIC_ENV_SECRET_CANARY"],Labels:{}},
  HostConfig:{LogConfig:{Config:{labels:"SYNTHETIC_HOST_SECRET_CANARY"}}},
  State:{Status:"created",StartedAt:"0001-01-01T00:00:00Z"},RestartCount:0,NetworkSettings:{},Mounts:[]}]' > "$root/containers.json"
cases=0
reset_case() {
  printf '%s\n' "$1" > "$root/mode"
  printf '0\n' > "$root/lists"; printf '0\n' > "$root/inspects"
  : > "$root/calls"; : > "$root/batch-sizes"
  cp "$root/raw-base.json" "$root/raw.json"
  [[ ! -d $root/inflight ]] || rmdir "$root/inflight"
}
run_case() {
  local mode=$1 expected=$2
  reset_case "$mode"
  if images_only_all_snapshots > "$root/output" 2> "$root/error"; then
    [[ $expected == pass ]] || { echo "UNEXPECTED_PASS=$mode"; return 1; }
  else
    [[ $expected == fail && ! -s $root/output ]] || { echo "UNEXPECTED_FAIL=$mode"; cat "$root/error"; return 1; }
    [[ $(< "$root/error") == IMAGE_INVENTORY_FAIL=bounded_snapshot ]]
  fi
  ! grep -Eq 'SECRET_CANARY|Config|Env|unrelated.secret' "$root/output" "$root/error"
  cases=$((cases+1))
}
run_case normal pass
[[ $(< "$root/inspects") == 10 && $(< "$root/lists") == 2 ]]
[[ $(sort -nu "$root/batch-sizes" | tail -n1) == 16 ]]
retention_all_snapshots > "$root/old"
cmp "$root/old" "$root/output"
[[ $(jq '.images|length' "$root/output") == 146 && $(jq '.containers|length' "$root/output") == 24 ]]
# Parser failures need only 17 images to cover the second batch; keep the first
# positive at the requested full scale. No production inventory is filtered.
jq '.[0:17]' "$root/raw-base.json" > "$root/short"
mv "$root/short" "$root/raw-base.json"
printf '[]\n' > "$root/containers.json"
run_case empty pass
[[ $(jq -c '.images' "$root/output") == '[]' ]]
run_case duplicate_listing pass
for mode in bad_listing blank_listing listing_cap unique_cap listing_nonzero missing duplicate foreign mandatory_null bad_size bad_alias partial_nonzero complete_nonzero batch_cap malformed nul extra_key duplicate_key second_batch_fail drift; do
  run_case "$mode" fail
done
jq '.[0].Config=null|del(.[1].Config)|.[2].Config.Labels=null|del(.[3].Config.Labels)|
  .[4].Config.Labels={}|.[5].Config.Labels["org.opencontainers.image.source"]="a\"b\nc中文<&>"|
  .[6].RepoTags=["tag:z","tag:a","tag:a"]|.[7].RepoTags=null|.[8].RepoDigests=null' "$root/raw-base.json" > "$root/special"
mv "$root/special" "$root/raw-base.json"
run_case special pass
retention_all_snapshots > "$root/old"
cmp "$root/old" "$root/output"

# Direct production watchdog tests: real owned shell process groups, no Docker.
# Short durations exercise the same primitive; they are not 120/300 benchmarks.
watch_case() {
  local name=$1 expected=$2 body=$3 work="$root/watch-$1" group start end
  mkdir "$work"
  printf '%s\n' 'set -Eeuo pipefail' \
    'read -r stat < "/proc/$BASHPID/stat"; rest=${stat##*) }; read -r state ppid group rest <<< "$rest"; printf "%s\n" "$group" > "$1/group"' \
    "$body" > "$work/worker"
  start=$(_images_only_now_ms)
  if ( _images_only_supervise "$work" "$start" 4300 ) > "$work/accepted" 2> "$work/error"; then
    [[ $expected == pass ]] || { echo "WATCH_UNEXPECTED_PASS=$name"; return 1; }
  else
    [[ $expected == fail && ! -s $work/accepted ]] || { echo "WATCH_UNEXPECTED_FAIL=$name"; return 1; }
  fi
  end=$(_images_only_now_ms)
  ((end-start<4500)) || { echo "WATCH_BUDGET_FAIL=$name"; return 1; }
  group=$(< "$work/group")
  ! kill -0 -- "-$group" 2>/dev/null || { echo "WATCH_RESIDUAL=$name"; return 1; }
  cases=$((cases+1))
}
watch_case success pass 'printf safe'
watch_case nonzero fail 'printf partial; exit 7'
watch_case held_pipe fail '(sleep 30 & exit 0) | head -c 100'
watch_case silent_child fail 'sleep 30 >/dev/null 2>&1 & printf safe; exit 0'
watch_case term_ignored fail 'trap "" TERM; sleep 30'
watch_case nested_foreground fail 'timeout --foreground --signal=TERM --kill-after=1s 30s bash -c "sleep 30 & wait"'
watch_case stdout_cap fail 'head -c 16777217 /dev/zero'

# A setup-consumed budget must reject without starting a worker at all. This
# uses the production primitive's absolute start; there is no clock override.
mkdir "$root/setup-budget"
printf '%s\n' 'printf launched > "$1/launched"' > "$root/setup-budget/worker"
started=$(_images_only_now_ms)
if ( _images_only_supervise "$root/setup-budget" "$((started-1000))" 4300 ) > "$root/setup-budget/accepted"; then
  echo WATCH_UNEXPECTED_PASS=setup_budget; exit 1
fi
[[ ! -e $root/setup-budget/launched && ! -s $root/setup-budget/accepted ]]
cases=$((cases+1))

# External termination reaches the actual Bash supervisor, which owns and
# reaps a separate GNU timeout group even when the producer ignores TERM.
for signal in TERM HUP; do
  work="$root/signal-$signal"
  mkdir "$work"
  printf '%s\n' 'set -Eeuo pipefail' \
    'read -r stat < "/proc/$BASHPID/stat"; rest=${stat##*) }; read -r state ppid group rest <<< "$rest"; printf "%s\n" "$group" > "$1/group"' \
    'trap "" TERM; sleep 30' > "$work/worker"
  started=$(_images_only_now_ms)
  _images_only_supervise "$work" "$started" 120000 > "$work/accepted" 2> "$work/error" &
  owned=$!
  ready=false
  for ((attempt=0;attempt<100;attempt++)); do
    if [[ -s $work/group ]]; then ready=true; break; fi
    sleep 0.01
  done
  [[ $ready == true ]] || { kill -TERM "$owned"; wait "$owned" || true; exit 1; }
  group=$(< "$work/group")
  kill -"$signal" "$owned"
  sleep 0.02
  kill -"$signal" "$owned" 2>/dev/null || true
  if wait "$owned"; then echo "WATCH_UNEXPECTED_PASS=external_$signal"; exit 1; fi
  [[ ! -s $work/accepted ]]
  ! kill -0 -- "-$group" 2>/dev/null || {
    kill -KILL -- "-$group" 2>/dev/null || true
    echo "WATCH_RESIDUAL=external_$signal"; exit 1
  }
  ended=$(_images_only_now_ms)
  ((ended-started<4500))
  cases=$((cases+1))
done

# Inject a second signal at the actual EXIT-cleanup command, before that
# cleanup function can ignore it. Only ignoring repeats in the FIRST signal
# handler closes this window; sleeping/retrying a green case is not evidence.
for signal in TERM HUP; do
  work="$root/reentry-$signal"
  mkdir "$work"
  printf '%s\n' 'set -Eeuo pipefail' \
    'read -r stat < "/proc/$BASHPID/stat"; rest=${stat##*) }; read -r state ppid group rest <<< "$rest"; printf "%s\n" "$group" > "$1/group"' \
    'trap "" TERM; sleep 30' > "$work/worker"
  started=$(_images_only_now_ms)
  (
    injected=false
    set -T
    trap 'if [[ ${FUNCNAME[0]:-} == _iis_exit && $injected == false ]]; then
        injected=true
        printf injected > "$work/second-signal"
        kill -"$signal" "$BASHPID"
      fi' DEBUG
    _images_only_supervise "$work" "$started" 120000
  ) > "$work/accepted" 2> "$work/error" &
  owned=$!
  ready=false
  for ((attempt=0;attempt<100;attempt++)); do
    if [[ -s $work/group ]]; then ready=true; break; fi
    sleep 0.01
  done
  [[ $ready == true ]] || { kill -TERM "$owned"; wait "$owned" || true; exit 1; }
  group=$(< "$work/group")
  kill -"$signal" "$owned"
  if wait "$owned"; then echo "REENTRY_UNEXPECTED_PASS=$signal"; exit 1; fi
  [[ -s $work/second-signal && ! -s $work/accepted ]] || {
    echo "REENTRY_INJECTION_OR_OUTPUT_FAIL=$signal"; exit 1
  }
  if kill -0 -- "-$group" 2>/dev/null; then
    kill -KILL -- "-$group" 2>/dev/null || true
    echo "REENTRY_RESIDUAL=$signal"; exit 1
  fi
  ended=$(_images_only_now_ms)
  ((ended-started<4500))
  cases=$((cases+1))
done

# Deterministically interrupt the fork-to-$! registration boundary, without
# modifying or copying production functions. DEBUG is confined to this test
# child and injects one signal immediately before the actual assignment.
for entry in primitive public; do
  for signal in TERM HUP; do
    work="$root/register-$entry-$signal"
    mkdir "$work"
    printf '%s\n' 'set -Eeuo pipefail' 'trap "" TERM; sleep 30' > "$work/worker"
    registration_command='pid=$!'
    [[ $entry == primitive ]] || registration_command='supervisor=$!'
    registration_signal=$signal
    started=$(_images_only_now_ms)
    if (
      injected=false
      set -T
      trap 'if [[ ${BASH_COMMAND:-} == "$registration_command" && $injected == false ]]; then
        injected=true
        printf "%s\n" "$!" > "$work/launched-pid"
        kill -"$registration_signal" "$BASHPID"
      fi' DEBUG
      if [[ $entry == primitive ]]; then
        _images_only_supervise "$work" "$started" 120000
      else
        images_only_all_snapshots
      fi
    ) > "$work/accepted" 2> "$work/error"; then
      echo "REGISTRATION_UNEXPECTED_PASS=$entry:$signal"; exit 1
    fi
    [[ -s $work/launched-pid && ! -s $work/accepted ]]
    owned=$(< "$work/launched-pid")
    [[ $owned =~ ^[1-9][0-9]*$ && $owned -gt 1 && $owned != "$BASHPID" ]]
    if kill -0 "$owned" 2>/dev/null || kill -0 -- "-$owned" 2>/dev/null; then
      kill -KILL "$owned" 2>/dev/null || true
      kill -KILL -- "-$owned" 2>/dev/null || true
      echo "REGISTRATION_RESIDUAL=$entry:$signal"; exit 1
    fi
    ended=$(_images_only_now_ms)
    ((ended-started<4500))
    cases=$((cases+1))
  done
done

# Aggregate storage is bounded before append; a seventeenth 1MiB canonical
# record cannot write even a partial extra record. No Docker is used here.
mkdir "$root/container-cap"
: > "$root/container-cap/containers"
head -c 1048576 /dev/zero > "$root/container-cap/container-snapshot"
for ((record=0;record<16;record++)); do _images_only_append_container "$root/container-cap"; done
if _images_only_append_container "$root/container-cap"; then echo CONTAINER_CAP_UNEXPECTED_PASS; exit 1; fi
[[ $(stat -c %s "$root/container-cap/containers") == 16777216 ]]
: > "$root/container-cap/containers"
head -c 1048577 /dev/zero > "$root/container-cap/container-snapshot"
if _images_only_append_container "$root/container-cap"; then echo CONTAINER_RECORD_CAP_UNEXPECTED_PASS; exit 1; fi
[[ ! -s $root/container-cap/containers ]]
cases=$((cases+2))
printf 'IMAGE_INVENTORY_TEST=PASS cases=%s full_images=146 full_containers=24 real_docker=NOT_RUN production=NOT_RUN\n' "$cases"
