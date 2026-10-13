#!/usr/bin/env bash
# Conservative, read-only startup-reference discovery. Docker labels select root
# Compose inputs; unrelated opt-in templates are not executed startup evidence.
# Historical release inputs remain protected. No dotenv/YAML is sourced or eval'd.
_ris_run() { timeout --signal=TERM --kill-after=1s 5s "$@"; }
_ris_hash_file() { local output; output=$(_ris_run sha256sum -- "$1") || return 1; printf '%s\n' "${output%% *}"; }
_ris_trim() { local value=$1; value=${value#"${value%%[![:space:]]*}"}; value=${value%"${value##*[![:space:]]}"}; printf '%s\n' "$value"; }

_ris_compose_metadata() {
  local ids raw format id
  local -a selected=()
  ids=$(_ris_run docker ps -aq --no-trunc) || return 1
  [[ -n "$ids" ]] || return 1
  mapfile -t selected <<< "$ids"
  ((${#selected[@]}<=256)) || return 1
  for id in "${selected[@]}"; do [[ "$id" =~ ^[a-f0-9]{64}$ ]] || return 1; done
  format='{"id":{{json .Id}},"project":{{json (index .Config.Labels "com.docker.compose.project")}},"workdir":{{json (index .Config.Labels "com.docker.compose.project.working_dir")}},"config_files":{{json (index .Config.Labels "com.docker.compose.project.config_files")}}}'
  raw=$(_ris_run docker inspect --format "$format" "${selected[@]}") || return 1
  ((${#raw}<=1048576)) || return 1
  _ris_run jq -csS 'sort_by(.id)' <<< "$raw"
}

# Returns an exact scalar, allowing ordinary quoting/comments but never escapes,
# YAML aliases, shell substitutions, concatenation or implicit coercion.
_ris_scalar() {
  local value double='^"([^"\\]*)"[[:space:]]*(#.*)?$' single="^'([^']*)'[[:space:]]*(#.*)?$"
  local plain='^([^[:space:]#]+)[[:space:]]*(#.*)?$'
  value=$(_ris_trim "$1") || return 1
  if [[ "$value" == \"* ]]; then [[ "$value" =~ $double ]] || return 1
  elif [[ "$value" == \'* ]]; then [[ "$value" =~ $single ]] || return 1
  else [[ "$value" =~ $plain ]] || return 1; fi
  value=${BASH_REMATCH[1]}
  [[ -n "$value" && "$value" != *\\* ]] || return 1
  printf '%s\n' "$value"
}

_ris_literal() {
  local value=$1 last
  if [[ "$value" =~ ^sha256:[a-f0-9]{64}$ ]]; then printf '%s\n' "$value"; return; fi
  if [[ "$value" =~ ^[a-f0-9]{64}$ ]]; then printf 'sha256:%s\n' "$value"; return; fi
  [[ "$value" != sha256:* && ! "$value" =~ ^[a-f0-9]{12,63}$ ]] || return 1
  # Standard registry/repository[:tag][@sha256:digest], not a URL or credential.
  local pattern='^[a-z0-9]+([._-][a-z0-9]+)*(:[0-9]{1,5})?(/[a-z0-9]+([._-][a-z0-9]+)*)*(:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(@sha256:[a-f0-9]{64})?$'
  [[ "$value" =~ $pattern ]] || return 1
  # Docker stores familiar names for its default registry. These spellings all
  # refer to the same local alias and must not evade startup protection.
  case "$value" in docker.io/*) value=${value#docker.io/} ;; index.docker.io/*) value=${value#index.docker.io/} ;; esac
  [[ "$value" != library/* ]] || value=${value#library/}
  last=${value##*/}
  [[ "$last" == *:* || "$last" == *@* ]] || value+=:latest
  printf '%s\n' "$value"
}

_ris_env_values() {
  local file=$1 only=${2:-} line value key size canonical
  [[ -f "$file" && ! -L "$file" ]] || return 1
  canonical=$(_ris_run realpath -e -- "$file") || return 1
  [[ "$canonical" == "$file" ]] || return 1
  size=$(_ris_run stat -c %s -- "$file") || return 1
  ((size<=262144)) || return 1
  local protected_keys='XBOARD_IMAGE|XBOARD_RELEASE_IMAGE|XBOARD_PREVIOUS_IMAGE|RELEASE_IMAGE|PREVIOUS_IMAGE|IMAGE|IMAGE_ID|MAINTENANCE_IMAGE|REDIS_IMAGE|RELEASE_SHA|LEGACY_RELEASE_SHA|SUPERSEDED_BY_SHA'
  local key_pattern="^(export[[:space:]]+)?($protected_keys)([[:space:]:=]|$)"
  local assignment="^(export[[:space:]]+)?($protected_keys)[[:space:]]*=[[:space:]]*(.*)$"
  while IFS= read -r line || [[ -n "$line" ]]; do
    line=${line%$'\r'}
    line=${line#"${line%%[![:space:]]*}"}; line=${line%"${line##*[![:space:]]}"}
    [[ "$line" =~ $key_pattern ]] || continue
    [[ "$line" =~ $assignment ]] || return 1
    key=${BASH_REMATCH[2]}; value=${BASH_REMATCH[3]}
    value=$(_ris_scalar "$value") || return 1
    case "$key" in
      *_SHA) [[ "$value" =~ ^[a-f0-9]{40}$ ]] || return 1 ;;
      IMAGE_ID) [[ "$value" =~ ^(sha256:)?[a-f0-9]{64}$ ]] || return 1; value=$(_ris_literal "$value") || return 1 ;;
      *) value=$(_ris_literal "$value") || return 1 ;;
    esac
    [[ -z "$only" || "$key" == "$only" ]] || continue
    printf '%s\n' "$value"
  done < "$file"
}

_ris_yaml_values() {
  local file=$1 root=$2 line clean value env_file found candidate canonical anchor anchors='|'
  local image_key='^image:[[:space:]]*(.*)$'
  local interpolation='^\$\{XBOARD_IMAGE(:\?[^{}$]*)?\}$'
  local anchor_definition='^[A-Za-z0-9_.-]+:[[:space:]]*&([A-Za-z0-9_-]+)[[:space:]]*(#.*)?$'
  local merge='^<<:[[:space:]]*\*([A-Za-z0-9_-]+)[[:space:]]*(#.*)?$'
  local interpolation_lexeme='\$\{[^{}]*\}'
  local empty_mapping='^[A-Za-z0-9_.-]+:[[:space:]]*\{[[:space:]]*\}[[:space:]]*(#.*)?$'
  local spaced_key='^[A-Za-z0-9_.-]+[[:space:]]+:'
  while IFS= read -r line || [[ -n "$line" ]]; do
    line=${line%$'\r'}
    [[ "$line" != *$'\t'* ]] || return 1
    ((${#line}<=8192)) || return 1
    clean=${line#"${line%%[![:space:]]*}"}; clean=${clean%"${clean##*[![:space:]]}"}
    [[ -n "$clean" && "$clean" != \#* ]] || continue
    # General YAML composition is intentionally unsupported. Known block-style
    # mapping anchors/merges are safe because every contained image is collected.
    [[ "$clean" != include:* && "$clean" != extends:* && "$clean" != \?* && "$clean" != \!* &&
       "$clean" != \"* && "$clean" != \'* && "$clean" != *'!!'* ]] || return 1
    [[ ! "$clean" =~ $spaced_key ]] || return 1
    if [[ "$clean" =~ $anchor_definition ]]; then anchors+="${BASH_REMATCH[1]}|"; fi
    if [[ "$clean" == '<<:'* ]]; then
      [[ "$clean" =~ $merge ]] || return 1
      anchor=${BASH_REMATCH[1]}
      [[ "$anchors" == *"|$anchor|"* ]] || return 1
      continue
    fi
    # Strip only bounded ${...} interpolation lexemes before rejecting flow
    # mappings. This does not evaluate any variable or make image interpolation
    # permissive; the exact image scalar is validated separately below.
    candidate=$clean
    while [[ "$candidate" =~ $interpolation_lexeme ]]; do
      candidate=${candidate/"${BASH_REMATCH[0]}"/INTERPOLATION}
    done
    if [[ "$candidate" == *'{'* || "$candidate" == *'}'* ]]; then
      # The shipped topology declares networks/volumes as empty mappings. No
      # image can be hidden in these; every nonempty flow mapping still fails.
      [[ "$clean" =~ $empty_mapping && "$clean" != image:* ]] || return 1
    fi
    [[ "$clean" =~ $image_key ]] || continue
    value=${BASH_REMATCH[1]}
    value=$(_ris_trim "$value") || return 1
    if [[ ! "$value" =~ $interpolation ]]; then value=$(_ris_scalar "$value") || return 1; fi
    if [[ "$value" == *'$'* ]]; then
      [[ "$value" =~ $interpolation ]] || return 1
      found=false
      for env_file in "$(dirname -- "$file")/runtime.env" "$(dirname -- "$file")/.env" "$root/.env"; do
        [[ -e "$env_file" || -L "$env_file" ]] || continue
        [[ -f "$env_file" && ! -L "$env_file" ]] || return 1
        canonical=$(_ris_run realpath -e -- "$env_file") || return 1
        [[ "$canonical" == "$env_file" ]] || return 1
        candidate=$(_ris_env_values "$env_file" XBOARD_IMAGE) || return 1
        [[ -n "$candidate" ]] || continue
        found=true
        printf '%s\n' "$candidate"
      done
      [[ "$found" == true ]] || return 1
    else _ris_literal "$value" || return 1; fi
  done < "$file"
}

# Shared discovery, not a cached file list. Callers run in a subshell so these
# three outputs cannot leak into the streamed executor's other helpers.
_ris_discover_inputs() {
  local root path directory found files='' metadata selected working config values line canonical metadata_hash
  local -a configs=()
  [[ -n "${workdir:-}" && "$workdir" == /* && ! -L "$workdir" &&
     "${XBOARD_ANCHOR_CONTAINER:-}" =~ ^[a-f0-9]{12,64}$ ]] || return 1
  root=$(_ris_run realpath -e -- "$workdir") || return 1
  [[ "$root" == "$workdir" && -f "${active_state_file:-}" && ! -L "$active_state_file" ]] || return 1
  metadata=$(_ris_compose_metadata) || return 1
  _ris_run jq -e 'type=="array" and length>0 and length<=256 and
    all(.[];(.id|type=="string" and test("^[a-f0-9]{64}$")) and
      all(.project,.workdir,.config_files;type=="string" or .==null)) and
    ([.[].id]|length==(unique|length))' <<< "$metadata" >/dev/null || return 1
  selected=$(_ris_run jq -ceS --arg root "$root" --arg anchor "$XBOARD_ANCHOR_CONTAINER" --arg active "${active_project:-}" '
    map(.project=(.project//"")|.workdir=(.workdir//"")|.config_files=(.config_files//"")) |
    map(select((.id|startswith($anchor)) or .workdir==$root or (.workdir|startswith($root+"/")) or
      ($active!="" and .project==$active)))|sort_by(.id)' <<< "$metadata") || return 1
  _ris_run jq -e --arg anchor "$XBOARD_ANCHOR_CONTAINER" '[.[]|select(.id|startswith($anchor))]|length==1' <<< "$selected" >/dev/null || return 1
  values=$(_ris_run jq -c '.[]' <<< "$selected") || return 1
  while IFS= read -r line; do
    working=$(_ris_run jq -er .workdir <<< "$line") || return 1
    config=$(_ris_run jq -er .config_files <<< "$line") || return 1
    [[ "$working" == "$root" || "$working" == "$root/"* ]] || return 1
    canonical=$(_ris_run realpath -e -- "$working") || return 1
    [[ "$canonical" == "$working" && ! -L "$working" ]] || return 1
    [[ -n "$config" && "$config" != ,* && "$config" != *, && "$config" != *,,* &&
       "$config" != *$'\n'* && "$config" != *$'\r'* ]] || return 1
    IFS=, read -r -a configs <<< "$config"
    for path in "${configs[@]}"; do
      [[ "$path" =~ ^/[A-Za-z0-9_./-]+\.ya?ml$ && "$path" == "$root/"* ]] || return 1
      files+="$path"$'\n'
    done
  done <<< "$values"
  metadata_hash=$(printf '%s\n' "$selected" | _ris_run sha256sum) || return 1
  metadata_hash=${metadata_hash%% *}
  files+="$active_state_file"$'\n'
  [[ ! -e "$root/.env" && ! -L "$root/.env" ]] || files+="$root/.env"$'\n'
  for directory in .codex-v2-release .codex-release .codex-stage .codex-admin-hotfix; do
    path="$root/$directory"
    [[ ! -L "$path" ]] || return 1
    [[ -d "$path" ]] || continue
    found=$(_ris_run find "$path" -mindepth 1 -maxdepth 1 -type l -print) || return 1
    [[ -z "$found" ]] || return 1
    found=$(_ris_run find "$path" -mindepth 1 -maxdepth 2 \
      \( -name 'state.json' -o -name 'state.env' -o -name 'compose*.yaml' -o -name 'compose*.yml' \
         -o -name 'docker-compose*.yaml' -o -name 'docker-compose*.yml' -o -name 'runtime.env' -o -name '.env' \) -print) || return 1
    files+="$found"$'\n'
  done
  files=$(printf '%s\n' "$files" | _ris_run sed '/^$/d' | _ris_run sort -u) || return 1
  [[ -n "$files" ]] || return 1
  _RIS_ROOT=$root
  _RIS_FILES=$files
  _RIS_METADATA_HASH=$metadata_hash
}

retention_startup_references() (
  set -o pipefail
  local root path files size sum=0 count=0 value key values hash after records='' raw_values=''
  local refs='' revisions='' ids='' metadata_hash canonical files_json refs_json revisions_json ids_json record
  _ris_discover_inputs || return 1
  root=$_RIS_ROOT; files=$_RIS_FILES; metadata_hash=$_RIS_METADATA_HASH
  while IFS= read -r path; do
    ((count+=1)); ((count<=256)) || return 1
    [[ -f "$path" && ! -L "$path" && "$path" == "$root/"* ]] || return 1
    canonical=$(_ris_run realpath -e -- "$path") || return 1
    [[ "$canonical" == "$path" ]] || return 1
    size=$(_ris_run stat -c %s -- "$path") || return 1
    ((size<=262144)) || return 1
    sum=$((sum+size)); ((sum<=8388608)) || return 1
    hash=$(_ris_hash_file "$path") || return 1
    # NUL bytes cannot be silently discarded by Bash scalar parsing.
    after=$(set -o pipefail; _ris_run tr -d '\000' < "$path" | _ris_run sha256sum) || return 1
    [[ "${after%% *}" == "$hash" ]] || return 1
    values=''
    case "$path" in
      */state.json)
        _ris_run jq -e 'type=="object" and all(to_entries[]|select(.key as $k |
          ["release_sha","legacy_release_sha","superseded_by_sha","release_image","previous_image",
           "image","image_id","maintenance_image","redis_image"]|index($k)!=null);
          .value|type=="string" and (test("[[:cntrl:]]")|not))' "$path" >/dev/null || return 1
        values=$(_ris_run jq -r 'to_entries[]|select(.key as $k |
          ["release_sha","legacy_release_sha","superseded_by_sha","release_image","previous_image",
           "image","image_id","maintenance_image","redis_image"]|index($k)!=null)|[.key,.value]|@tsv' "$path") || return 1
        while IFS=$'\t' read -r key value; do
          [[ -n "$key" && -n "$value" ]] || continue
          case "$key" in
            *_sha) [[ "$value" =~ ^[a-f0-9]{40}$ ]] || return 1; raw_values+="$value"$'\n' ;;
            image_id) [[ "$value" =~ ^(sha256:)?[a-f0-9]{64}$ ]] || return 1; value=$(_ris_literal "$value") || return 1; raw_values+="$value"$'\n' ;;
            *) value=$(_ris_literal "$value") || return 1; raw_values+="$value"$'\n' ;;
          esac
        done <<< "$values"
        ;;
      *.yaml|*.yml) values=$(_ris_yaml_values "$path" "$root") || return 1; raw_values+="$values"$'\n' ;;
      */.env|*/runtime.env|*/state.env) values=$(_ris_env_values "$path") || return 1; raw_values+="$values"$'\n' ;;
      *) return 1 ;;
    esac
    after=$(_ris_hash_file "$path") || return 1
    [[ "$after" == "$hash" ]] || return 1
    record=$(_ris_run jq -cnS --arg path "$path" --arg sha256 "$hash" '{path:$path,sha256:$sha256}') || return 1
    records+="$record"$'\n'
  done <<< "$files"
  while IFS= read -r value; do
    [[ -n "$value" ]] || continue
    if [[ "$value" =~ ^[a-f0-9]{40}$ ]]; then revisions+="$value"$'\n'
    elif [[ "$value" =~ ^sha256:[a-f0-9]{64}$ ]]; then ids+="$value"$'\n'
    else refs+="$value"$'\n'; fi
  done <<< "$raw_values"
  files_json=$(_ris_run jq -cs 'sort_by(.path)' <<< "$records") || return 1
  refs_json=$(_ris_run jq -Rs 'split("\n")|map(select(length>0))|unique' <<< "$refs") || return 1
  revisions_json=$(_ris_run jq -Rs 'split("\n")|map(select(length>0))|unique' <<< "$revisions") || return 1
  ids_json=$(_ris_run jq -Rs 'split("\n")|map(select(length>0))|unique' <<< "$ids") || return 1
  _ris_run jq -cnS --arg metadata "$metadata_hash" --argjson files "$files_json" \
    --argjson references "$refs_json" --argjson revisions "$revisions_json" --argjson ids "$ids_json" \
    '{schema_version:1,files:$files,compose_metadata_sha256:$metadata,image_ids:$ids,revisions:$revisions,
      digests:([$references[]|select(contains("@sha256:"))|sub(":[^/:@]+@sha256:";"@sha256:")]|unique),
      references:$references}'
)

# The first locked audit still fully parses all inputs above. Repeated checks
# can prove the same result without reparsing identical bytes or starting
# thousands of short-lived processes. Every pass rediscovers the complete file
# set and Compose metadata; no mtime-only cache or failed-check fallback exists.
retention_startup_revalidate() (
  set -o pipefail
  local expected=$1 expected_hash=$2 paths checksums metadata path dependency canonical sizes size sum pass
  local hashes identities initial_identities='' total expected_raw expected_size current_hash
  local -a selected=() lengths=()
  [[ "$expected_hash" =~ ^[a-f0-9]{64}$ && -f "$expected" && ! -L "$expected" ]] || return 1
  [[ "$(_ris_hash_file "$expected")" == "$expected_hash" ]] || return 1
  expected_size=$(_ris_run stat -c %s -- "$expected") || return 1
  [[ "$expected_size" =~ ^[0-9]{1,9}$ ]] && ((expected_size>0 && expected_size<=8388608)) || return 1
  # Bind the bytes actually consumed, not just the pathname before/after reads.
  # The sentinel preserves trailing newlines removed by command substitution.
  expected_raw=$(_ris_run head -c 8388609 -- "$expected" && printf '.') || return 1
  expected_raw=${expected_raw%.}
  current_hash=$(printf '%s' "$expected_raw" | _ris_run sha256sum) || return 1
  [[ "${current_hash%% *}" == "$expected_hash" ]] || return 1
  _ris_run jq -e 'type=="object" and .schema_version==1 and
    (.compose_metadata_sha256|type=="string" and test("^[a-f0-9]{64}$")) and
    (.files|type=="array" and length>0 and length<=256 and .==sort_by(.path) and
      all(.[];(.path|type=="string" and startswith("/") and (test("[[:cntrl:]\\\\]")|not)) and
        (.sha256|type=="string" and test("^[a-f0-9]{64}$"))) and
      ([.[].path]|length==(unique|length)))' <<< "$expected_raw" >/dev/null || return 1
  paths=$(_ris_run jq -r '.files[].path' <<< "$expected_raw") || return 1
  checksums=$(_ris_run jq -r '.files[]|.sha256+"  "+.path' <<< "$expected_raw") || return 1
  metadata=$(_ris_run jq -er .compose_metadata_sha256 <<< "$expected_raw") || return 1
  mapfile -t selected <<< "$paths"
  total=${#selected[@]}
  for pass in 1 2 3; do
    _ris_discover_inputs || return 1
    [[ "$_RIS_METADATA_HASH" == "$metadata" ]] || return 1
    _ris_run jq -e --arg paths "$_RIS_FILES" \
      '([.files[].path]|sort)==($paths|split("\n")|sort)' <<< "$expected_raw" >/dev/null || return 1
    for path in "${selected[@]}"; do
      [[ "$path" == "$_RIS_ROOT/"* && -f "$path" && ! -L "$path" ]] || return 1
      # The parser can also read these dotenv candidates. Do not reuse a
      # manifest that omitted one, including a newly created indirect input.
      if [[ "$path" == *.yaml || "$path" == *.yml ]]; then
        for dependency in "${path%/*}/runtime.env" "${path%/*}/.env" "$_RIS_ROOT/.env"; do
          [[ -e "$dependency" || -L "$dependency" ]] || continue
          [[ -f "$dependency" && ! -L "$dependency" &&
             $'\n'"$paths"$'\n' == *$'\n'"$dependency"$'\n'* ]] || return 1
        done
      fi
    done
    canonical=$(_ris_run realpath -e -- "${selected[@]}") || return 1
    [[ "$canonical" == "$paths" ]] || return 1
    sizes=$(_ris_run stat -c %s -- "${selected[@]}") || return 1
    mapfile -t lengths <<< "$sizes"
    ((${#lengths[@]}==total)) || return 1
    sum=0
    for size in "${lengths[@]}"; do
      [[ "$size" =~ ^[0-9]{1,9}$ ]] && ((size<=262144)) || return 1
      sum=$((sum+size)); ((sum<=8388608)) || return 1
    done
    identities=$(_ris_run stat -c '%d:%i:%s:%y:%z' -- "${selected[@]}") || return 1
    if [[ "$pass" == 1 ]]; then initial_identities=$identities
    else [[ "$identities" == "$initial_identities" ]] || return 1; fi
    if ((pass<3)); then
      hashes=$(_ris_run sha256sum -- "${selected[@]}") || return 1
      [[ "$hashes" == "$checksums" ]] || return 1
    fi
  done
  # Pass three rechecks discovery/dependencies after the second hash read.
  # Reject a replacement during that read, as well as a changed baseline.
  identities=$(_ris_run stat -c '%d:%i:%s:%y:%z' -- "${selected[@]}") || return 1
  canonical=$(_ris_run realpath -e -- "${selected[@]}") || return 1
  current_hash=$(_ris_hash_file "$expected") || return 1
  [[ "$identities" == "$initial_identities" && "$canonical" == "$paths" &&
     ! -L "$expected" && "$current_hash" == "$expected_hash" ]] || return 1
)
