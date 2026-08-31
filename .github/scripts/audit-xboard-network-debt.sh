#!/usr/bin/env bash
set -Eeuo pipefail

# This script is concatenated after production-runtime-discovery.sh,
# release-state.sh, and audit-xboard-retention.sh. The retention audit resolves
# and protects the active runtime, the direct rollback runtime, data identity,
# and the deployment lock before network debt is classified.

if [[ -z "${inventory:-}" || ! -f "$inventory" ||
      -z "${workdir:-}" || -z "${active_project:-}" ||
      -z "${active_revision:-}" || -z "${active_release_id:-}" ||
      -z "${active_app_data_id:-}" || -z "${active_redis_volume:-}" ]]; then
  echo 'NETWORK_DEBT_AUDIT_FAIL=retention_context_missing' >&2
  exit 1
fi
[[ "$active_project" == "xboard-v2-$active_release_id" ]] || {
  echo 'NETWORK_DEBT_AUDIT_FAIL=active_project_mismatch' >&2
  exit 1
}

for tool in docker jq mktemp sha256sum sort; do
  command -v "$tool" >/dev/null || {
    echo "NETWORK_DEBT_AUDIT_FAIL=missing_tool:$tool" >&2
    exit 1
  }
done

network_inventory=$(mktemp)
trap 'rm -f -- "$inventory" "$network_inventory"' EXIT
chmod 600 "$network_inventory"

network_emit() {
  printf '%s\n' "$*" | tee -a "$network_inventory"
}

network_debt_inspect_row() {
  docker network inspect "$1" | jq -r '.[0] | [
    .Id,
    .Name,
    (.Driver // "none"),
    (.Scope // "none"),
    ((.Internal // false) | tostring),
    (.Labels["com.docker.compose.project"] // "none"),
    (.Labels["com.docker.compose.network"] // "none"),
    (((.Containers // {}) | length) | tostring),
    ([.IPAM.Config[]? | .Subnet // empty] | sort | join(",") | if . == "" then "none" else . end),
    (.Created // "none")
  ] | @tsv'
}

network_emit 'NETWORK_DEBT_AUDIT_SCHEMA=1'
network_emit "NETWORK_DEBT_WORKFLOW_SHA=$EXPECTED_WORKFLOW_SHA"
network_emit "NETWORK_DEBT_ACTIVE_PROJECT=$active_project"
network_emit "NETWORK_DEBT_ACTIVE_RELEASE_ID=$active_release_id"
network_emit "NETWORK_DEBT_ACTIVE_REVISION=$active_revision"
network_emit "NETWORK_DEBT_ACTIVE_UPSTREAM=$XBOARD_ACTIVE_UPSTREAM"
# Populated by the concatenated retention audit.
# shellcheck disable=SC2154
network_emit "NETWORK_DEBT_ACTIVE_APP_DATA_ID=$active_app_data_id"
# shellcheck disable=SC2154
network_emit "NETWORK_DEBT_ACTIVE_REDIS_VOLUME=$active_redis_volume"
network_emit "NETWORK_DEBT_DIRECT_PREVIOUS_PROJECT=${direct_previous_project:-none}"

network_count=0
candidate_count=0
protected_count=0
unrelated_count=0
attached_retired_count=0
invalid_xboard_count=0

while IFS= read -r listed_id; do
  [[ -n "$listed_id" ]] || continue
  row=$(network_debt_inspect_row "$listed_id")
  IFS=$'\t' read -r id name driver scope internal project logical endpoints subnets created_at <<< "$row"
  classification=unrelated
  release_id=none
  traffic_state=none
  revision=none
  reason=not_xboard_v2

  if [[ "$project" == "$active_project" ]]; then
    classification=protected
    reason=active_project
  elif [[ -n "${direct_previous_project:-}" && "$project" == "$direct_previous_project" ]]; then
    classification=protected
    reason=direct_rollback_project
  elif [[ "$project" =~ ^xboard-v2-([0-9]+-[0-9]+)$ ]]; then
    release_id=${BASH_REMATCH[1]}
    release_dir="$workdir/.codex-v2-release/$release_id"
    state_file="$release_dir/state.json"
    expected_name="${project}_${logical}"
    expected_internal=false
    case "$logical" in
      edge|backplane) expected_internal=true ;;
      ingress|egress) ;;
      *) expected_internal=invalid ;;
    esac

    if [[ "$driver" != bridge || "$scope" != local ||
          "$expected_internal" == invalid || "$internal" != "$expected_internal" ||
          "$name" != "$expected_name" || ! -d "$release_dir" || -L "$release_dir" ||
          "$(realpath -e -- "$release_dir" 2>/dev/null || true)" != "$release_dir" ||
          ! -f "$state_file" || -L "$state_file" ]] ||
       ! release_state_validate "$state_file" 2>/dev/null; then
      classification=invalid_xboard
      reason=identity_or_state_invalid
    else
      state_release_id=$(release_state_get "$state_file" release_id)
      state_project=$(release_state_get "$state_file" project_name)
      traffic_state=$(release_state_get "$state_file" traffic_state)
      revision=$(release_state_get "$state_file" release_sha)
      if [[ "$state_release_id" != "$release_id" || "$state_project" != "$project" ||
            ! "$revision" =~ ^[a-f0-9]{40}$ ]]; then
        classification=invalid_xboard
        reason=state_identity_mismatch
      elif [[ "$endpoints" != 0 ]]; then
        classification=attached_retired
        reason=container_endpoints_present
      else
        case "$traffic_state" in
          rolled_back|finalized|active_v2)
            classification=candidate
            reason=empty_retired_release_network
            ;;
          *)
            classification=invalid_xboard
            reason="unsafe_traffic_state:$traffic_state"
            ;;
        esac
      fi
    fi
  fi

  ((network_count += 1))
  case "$classification" in
    candidate) ((candidate_count += 1)) ;;
    protected) ((protected_count += 1)) ;;
    attached_retired) ((attached_retired_count += 1)) ;;
    invalid_xboard) ((invalid_xboard_count += 1)) ;;
    unrelated) ((unrelated_count += 1)) ;;
    *) echo "NETWORK_DEBT_AUDIT_FAIL=unknown_classification:$classification" >&2; exit 1 ;;
  esac
  network_emit "NETWORK_DEBT_NETWORK name=$name id=$id classification=$classification reason=$reason project=$project logical=$logical driver=$driver scope=$scope internal=$internal endpoints=$endpoints release_id=$release_id traffic_state=$traffic_state revision=$revision subnets=$subnets created_at=$created_at"
done < <(docker network ls -q --no-trunc | sort)

network_emit "NETWORK_DEBT_NETWORK_COUNT=$network_count"
network_emit "NETWORK_DEBT_CANDIDATE_COUNT=$candidate_count"
network_emit "NETWORK_DEBT_PROTECTED_COUNT=$protected_count"
network_emit "NETWORK_DEBT_ATTACHED_RETIRED_COUNT=$attached_retired_count"
network_emit "NETWORK_DEBT_INVALID_XBOARD_COUNT=$invalid_xboard_count"
network_emit "NETWORK_DEBT_UNRELATED_COUNT=$unrelated_count"

((invalid_xboard_count == 0)) || {
  echo "NETWORK_DEBT_AUDIT_FAIL=invalid_xboard_networks:$invalid_xboard_count" >&2
  exit 1
}
((attached_retired_count == 0)) || {
  echo "NETWORK_DEBT_AUDIT_FAIL=attached_retired_networks:$attached_retired_count" >&2
  exit 1
}

network_debt_fingerprint=$(
  { grep -E '^(NETWORK_DEBT_ACTIVE_|NETWORK_DEBT_DIRECT_PREVIOUS_PROJECT=|NETWORK_DEBT_NETWORK .*classification=candidate )' "$network_inventory" || true; } |
    sort |
    sha256sum |
    awk '{print $1}'
)
network_debt_audit_fingerprint=$(sort "$network_inventory" | sha256sum | awk '{print $1}')
echo "NETWORK_DEBT_FINGERPRINT=$network_debt_fingerprint"
echo "NETWORK_DEBT_AUDIT_FINGERPRINT=$network_debt_audit_fingerprint"
echo 'NETWORK_DEBT_AUDIT=PASS mode=read_only'
