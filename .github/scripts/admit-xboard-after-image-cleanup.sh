#!/usr/bin/env bash
set -Eeuo pipefail
# Read-only pre-admission. Mutators check again after owning deploy.lock.
: "${EXPECTED_WORKFLOW_SHA:?required}"
[[ "$EXPECTED_WORKFLOW_SHA" =~ ^[a-f0-9]{40}$ ]]
xboard_find_compose_anchor
workdir=$(realpath -e -- "$XBOARD_ANCHOR_WORKDIR")
retention_continuity_admit_deployment "$workdir"
echo 'IMAGE_CLEANUP_DEPLOYMENT_ADMISSION=PASS separate_deployment_authorization_required'
