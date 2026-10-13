#!/usr/bin/env bash
set -Eeuo pipefail
root=${IMAGE_INVENTORY_FIXTURE_ROOT:?}
mode=$(< "$root/mode")
printf '%s\n' "${1:-}:${2:-}:$#" >> "$root/calls"
mkdir "$root/inflight" || exit 96
trap 'rmdir "$root/inflight"' EXIT
if [[ "$1 ${2:-}" == 'ps -aq' ]]; then
  jq -r '.[].Id' "$root/containers.json"
elif [[ "$1" == inspect && $# == 2 ]]; then
  jq -ce --arg id "$2" '[.[]|select(.Id==$id)]|select(length==1)' "$root/containers.json"
elif [[ "$1 ${2:-}" == 'image inspect' && $# == 3 ]]; then
  jq -ce --arg id "$3" '[.[]|select(.Id==$id)]|select(length==1)' "$root/raw.json"
elif [[ "$1 $2" == 'image ls' && $# == 4 && "$3 $4" == '-aq --no-trunc' ]]; then
  count=$(< "$root/lists"); count=$((count+1)); printf '%s\n' "$count" > "$root/lists"
  case "$mode" in
    empty) exit 0 ;;
    bad_listing) printf 'sha256:short\n'; exit 0 ;;
    blank_listing) printf '\n'; exit 0 ;;
    listing_cap) head -c 131073 /dev/zero | tr '\000' x; exit 0 ;;
    unique_cap) for ((n=1;n<=257;n++)); do printf 'sha256:%064x\n' "$n"; done; exit 0 ;;
    listing_nonzero) jq -r '.[].Id' "$root/raw.json"; exit 4 ;;
    duplicate_listing) jq -r '.[].Id' "$root/raw.json"; jq -r '.[].Id' "$root/raw.json"; exit 0 ;;
    drift) if ((count==2)); then jq -r '.[1:]|.[].Id' "$root/raw.json"; exit 0; fi ;;
  esac
  jq -r '.[].Id' "$root/raw.json"
elif [[ "$1 $2 $3" == 'image inspect --format' ]]; then
  format=$4; shift 4
  (($#>=1 && $#<=16)) || exit 94
  printf '%s\n' "$#" >> "$root/batch-sizes"
  count=$(< "$root/inspects"); count=$((count+1)); printf '%s\n' "$count" > "$root/inspects"
  selected=$(jq -nc --args '$ARGS.positional' -- "$@")
  data=$(jq -c --argjson ids "$selected" '[.[]|select(.Id as $id|$ids|index($id)!=null)]|reverse' "$root/raw.json")
  printf 'SYNTHETIC_STDERR_SECRET_CANARY\n' >&2
  case "$mode" in
    missing) data=$(jq -c '.[1:]' <<< "$data") ;;
    duplicate) data=$(jq -c '.[1]=.[0]' <<< "$data") ;;
    foreign) data=$(jq -c '.[0].Id="sha256:"+("f"*64)' <<< "$data") ;;
    mandatory_null) data=$(jq -c '.[0].Created=null' <<< "$data") ;;
    mandatory_missing) data=$(jq -c 'del(.[0].Created)' <<< "$data") ;;
    bad_size) data=$(jq -c '.[0].Size=9007199254740992' <<< "$data") ;;
    bad_alias) data=$(jq -c '.[0].RepoTags=[7]' <<< "$data") ;;
    partial_nonzero) data=$(jq -c '.[1:]' <<< "$data") ;;
    batch_cap) head -c 1048577 /dev/zero | tr '\000' x; exit 0 ;;
    # Exactly cap+1 bytes reach the sink before a deterministic nonzero exit.
    # Do not assume GNU tools always turn EPIPE into signal exit status 141:
    # an inherited ignored SIGPIPE makes them report a write error and exit 1.
    batch_cap_status141) head -c 1048577 /dev/zero | tr '\000' x; exit 141 ;;
    batch_cap_pipe)
      if head -c 2097152 /dev/zero | tr '\000' x; then status=0; else status=$?; fi
      printf '%s\n' "$status" > "$root/oversize-pipe-status"
      exit "$status" ;;
    malformed) printf '{\n'; exit 0 ;;
    nul) printf '\000'; exit 0 ;;
    hang) trap '' TERM; sleep 30; exit 0 ;;
    second_batch_fail) if ((count==2)); then printf '{\n'; exit 7; fi ;;
  esac
  if [[ "$mode" == extra_key ]]; then
    "$root/bin/template-render" "$format" <<< "$data" | sed '1s/^{/{"Config":{"Env":["SYNTHETIC_STDOUT_SECRET_CANARY"]},/'
  elif [[ "$mode" == duplicate_key ]]; then
    "$root/bin/template-render" "$format" <<< "$data" | sed '1s/^{/{"Size":1,/'
  else
    "$root/bin/template-render" "$format" <<< "$data"
  fi
  case "$mode" in partial_nonzero|complete_nonzero) exit 7 ;; esac
else
  printf 'FIXTURE_UNEXPECTED_COMMAND\n' >&2; exit 90
fi
