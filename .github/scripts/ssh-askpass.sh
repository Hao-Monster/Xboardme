#!/usr/bin/env bash
set -euo pipefail

: "${SSHPASS:?SSHPASS is required}"

prompt=${1:-}
case "${prompt,,}" in
  *password*) printf '%s\n' "$SSHPASS" ;;
  *)
    echo 'Refusing an unexpected SSH prompt.' >&2
    exit 1
    ;;
esac
