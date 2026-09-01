#!/usr/bin/env bash
set -Eeuo pipefail

# This script is appended to the read-only retention audit and consumes the
# exact active release identity discovered by that audit. It performs no host
# mutation; it only chooses an unused loopback port and declares whether the
# oldest rollback generation must be rotated before cutover.

: "${EXPECTED_CANDIDATE_SHA:?EXPECTED_CANDIDATE_SHA is required}"
: "${PREFERRED_STAGE_PORT:=0}"

release_slot_fail() {
  echo "RELEASE_SLOT_FAIL=$1" >&2
  return 1
}

[[ "$EXPECTED_CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ ]] || release_slot_fail invalid_candidate_sha
[[ "$PREFERRED_STAGE_PORT" =~ ^[0-9]+$ ]] || release_slot_fail invalid_preferred_port
((PREFERRED_STAGE_PORT == 0 || (PREFERRED_STAGE_PORT >= 7003 && PREFERRED_STAGE_PORT <= 7010))) ||
  release_slot_fail preferred_port_out_of_range
[[ "${XBOARD_ACTIVE_TOPOLOGY:-}" == v2 ]] || release_slot_fail active_topology_not_v2
[[ "${active_release_id:-}" =~ ^[0-9]+-[0-9]+$ ]] || release_slot_fail active_release_id_invalid
[[ "${active_revision:-}" =~ ^[0-9a-f]{40}$ ]] || release_slot_fail active_revision_invalid
[[ "$EXPECTED_CANDIDATE_SHA" != "$active_revision" ]] || release_slot_fail candidate_matches_active

case "${active_traffic_state:-}:${active_rollback_supported:-}" in
  active_v2:true) rotation_required=true ;;
  finalized:false) rotation_required=false ;;
  finalizing:true)
    active_finalize_reason=$(release_state_get_optional "$active_state_file" finalize_reason)
    active_superseding_sha=$(release_state_get_optional "$active_state_file" superseded_by_sha)
    [[ "$active_finalize_reason" == superseded && "$active_superseding_sha" == "$EXPECTED_CANDIDATE_SHA" ]] ||
      release_slot_fail incompatible_finalize_in_progress
    rotation_required=true
    ;;
  *) release_slot_fail unsupported_active_lifecycle ;;
esac

active_maintenance_port=$(release_state_get_optional "$active_state_file" maintenance_port)
[[ "$active_maintenance_port" =~ ^[0-9]+$ ]] || release_slot_fail active_maintenance_port_invalid

if ! declare -F release_slot_listener_count >/dev/null; then
  release_slot_listener_count() {
    ss -H -lnt "( sport = :$1 )" 2>/dev/null | wc -l | tr -d '[:space:]'
  }
fi
if ! declare -F release_slot_caddy_reference_count >/dev/null; then
  release_slot_caddy_reference_count() {
    grep -Ec "reverse_proxy[[:space:]]+127\\.0\\.0\\.1:$1([[:space:]]|$)" "$XBOARD_CADDY_FILE" || true
  }
fi

candidate_ports=()
if ((PREFERRED_STAGE_PORT != 0)); then
  candidate_ports+=("$PREFERRED_STAGE_PORT")
fi
for port in {7003..7010}; do
  [[ "$port" == "$PREFERRED_STAGE_PORT" ]] || candidate_ports+=("$port")
done

stage_port=''
for port in "${candidate_ports[@]}"; do
  [[ "$port" != "$XBOARD_ACTIVE_PORT" && "$port" != "$active_maintenance_port" ]] || continue
  [[ "$(release_slot_listener_count "$port")" == 0 ]] || continue
  [[ "$(release_slot_caddy_reference_count "$port")" == 0 ]] || continue
  stage_port=$port
  break
done
[[ -n "$stage_port" ]] || release_slot_fail no_free_stage_port

printf 'RELEASE_SLOT_SCHEMA=1\n'
printf 'RELEASE_SLOT_ACTIVE_RELEASE_ID=%s\n' "$active_release_id"
printf 'RELEASE_SLOT_ACTIVE_REVISION=%s\n' "$active_revision"
printf 'RELEASE_SLOT_ACTIVE_TRAFFIC_STATE=%s\n' "$active_traffic_state"
printf 'RELEASE_SLOT_ROTATION_REQUIRED=%s\n' "$rotation_required"
printf 'RELEASE_SLOT_STAGE_PORT=%s\n' "$stage_port"
printf 'RELEASE_SLOT_PLAN=PASS mode=read_only\n'
