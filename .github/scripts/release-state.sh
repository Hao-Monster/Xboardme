# Shared release-state helpers. This file is prepended to deployment scripts
# when they are streamed over SSH, and can also be sourced for local checks.

release_state_require_tool() {
  command -v jq >/dev/null 2>&1 || {
    echo 'RELEASE_STATE_FAIL=jq_missing' >&2
    return 1
  }
}

release_state_validate() {
  local state_file=$1
  release_state_require_tool || return 1
  [[ -f "$state_file" && ! -L "$state_file" ]] || {
    echo 'RELEASE_STATE_FAIL=invalid_file' >&2
    return 1
  }
  jq -e '
    type == "object" and
    .schema_version == 2 and
    all(keys[]; test("^[a-z][a-z0-9_]*$")) and
    all(to_entries[]; .key == "schema_version" or (.value | type == "string"))
  ' "$state_file" >/dev/null || {
    echo 'RELEASE_STATE_FAIL=invalid_schema' >&2
    return 1
  }
}

release_state_get() {
  local state_file=$1 key=$2
  release_state_validate "$state_file" || return 1
  [[ "$key" =~ ^[a-z][a-z0-9_]*$ ]] || {
    echo 'RELEASE_STATE_FAIL=invalid_key' >&2
    return 1
  }
  jq -er --arg key "$key" '
    if has($key) and (.[$key] | type == "string") then .[$key]
    else error("missing or non-string release state key")
    end
  ' "$state_file"
}

release_state_get_optional() {
  local state_file=$1 key=$2
  release_state_validate "$state_file" || return 1
  [[ "$key" =~ ^[a-z][a-z0-9_]*$ ]] || {
    echo 'RELEASE_STATE_FAIL=invalid_key' >&2
    return 1
  }
  jq -er --arg key "$key" '
    if has($key) then
      if .[$key] | type == "string" then .[$key]
      else error("non-string release state key")
      end
    else ""
    end
  ' "$state_file"
}

release_state_create() {
  local state_file=$1 key value temporary next
  shift
  release_state_require_tool || return 1
  (($# % 2 == 0)) || {
    echo 'RELEASE_STATE_FAIL=invalid_pairs' >&2
    return 1
  }
  [[ ! -e "$state_file" && ! -L "$state_file" ]] || {
    echo 'RELEASE_STATE_FAIL=already_exists' >&2
    return 1
  }

  temporary=$(mktemp "${state_file}.XXXXXX")
  chmod 600 "$temporary"
  jq -n '{schema_version: 2}' > "$temporary"
  while (($# > 0)); do
    key=$1
    value=$2
    shift 2
    [[ "$key" =~ ^[a-z][a-z0-9_]*$ && "$key" != schema_version ]] || {
      rm -f -- "$temporary"
      echo 'RELEASE_STATE_FAIL=invalid_key' >&2
      return 1
    }
    next=$(mktemp "${state_file}.XXXXXX")
    if ! jq --arg key "$key" --arg value "$value" '. + {($key): $value}' "$temporary" > "$next"; then
      rm -f -- "$temporary" "$next"
      return 1
    fi
    chmod 600 "$next"
    mv -f -- "$next" "$temporary"
  done
  mv -- "$temporary" "$state_file"
  release_state_validate "$state_file"
}

release_state_set() {
  local state_file=$1 key=$2 value=$3 temporary
  release_state_validate "$state_file" || return 1
  [[ "$key" =~ ^[a-z][a-z0-9_]*$ && "$key" != schema_version ]] || {
    echo 'RELEASE_STATE_FAIL=invalid_key' >&2
    return 1
  }
  temporary=$(mktemp "${state_file}.XXXXXX")
  if ! jq --arg key "$key" --arg value "$value" '. + {($key): $value}' "$state_file" > "$temporary"; then
    rm -f -- "$temporary"
    return 1
  fi
  chmod 600 "$temporary"
  mv -f -- "$temporary" "$state_file"
  release_state_validate "$state_file"
}

release_state_decode_legacy_value() {
  local encoded=$1 decoded='' character escaped=false index
  if [[ "$encoded" == "''" ]]; then
    printf '%s' ''
    return 0
  fi
  if [[ "$encoded" == \$\'* || "$encoded" == *$'\n'* || "$encoded" == *$'\r'* ]]; then
    echo 'RELEASE_STATE_FAIL=unsupported_legacy_encoding' >&2
    return 1
  fi

  for ((index = 0; index < ${#encoded}; index++)); do
    character=${encoded:index:1}
    if [[ "$escaped" == true ]]; then
      decoded+=$character
      escaped=false
    elif [[ "$character" == '\' ]]; then
      escaped=true
    else
      decoded+=$character
    fi
  done
  if [[ "$escaped" == true ]]; then
    echo 'RELEASE_STATE_FAIL=invalid_legacy_escape' >&2
    return 1
  fi
  printf '%s' "$decoded"
}

release_state_import_legacy() {
  local legacy_file=$1 state_file=$2 line key encoded decoded normalized_key mode
  local -a pairs=()
  local -A seen=()

  [[ -f "$legacy_file" && ! -L "$legacy_file" && ! -e "$state_file" ]] || {
    echo 'RELEASE_STATE_FAIL=invalid_legacy_file' >&2
    return 1
  }
  mode=$(stat -c '%a' "$legacy_file")
  [[ "$mode" == 600 ]] || {
    echo 'RELEASE_STATE_FAIL=insecure_legacy_permissions' >&2
    return 1
  }

  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" == *=* ]] || {
      echo 'RELEASE_STATE_FAIL=invalid_legacy_line' >&2
      return 1
    }
    key=${line%%=*}
    encoded=${line#*=}
    [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ && "$key" != SCHEMA_VERSION && -z "${seen[$key]:-}" ]] || {
      echo 'RELEASE_STATE_FAIL=invalid_legacy_key' >&2
      return 1
    }
    seen[$key]=1
    decoded=$(release_state_decode_legacy_value "$encoded") || return 1
    normalized_key=${key,,}
    pairs+=("$normalized_key" "$decoded")
  done < "$legacy_file"

  ((${#pairs[@]} > 0)) || {
    echo 'RELEASE_STATE_FAIL=empty_legacy_file' >&2
    return 1
  }
  release_state_create "$state_file" "${pairs[@]}"
}

release_state_open() {
  local release_dir=$1
  local state_file="$release_dir/state.json" legacy_file="$release_dir/state.env"
  if [[ -e "$state_file" ]]; then
    release_state_validate "$state_file" || return 1
  elif [[ -e "$legacy_file" ]]; then
    release_state_import_legacy "$legacy_file" "$state_file" || return 1
  else
    return 1
  fi
  printf '%s\n' "$state_file"
}
