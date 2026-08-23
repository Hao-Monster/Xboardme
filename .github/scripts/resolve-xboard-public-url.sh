#!/usr/bin/env bash
set -Eeuo pipefail

mapfile -t caddy_configs < <(
  grep -RIlE --include='*.conf' --include='Caddyfile' \
    -- '127\.0\.0\.1:700[123]' /etc/caddy 2>/dev/null || true
)
if ((${#caddy_configs[@]} != 1)); then
  echo "PUBLIC_URL_FAIL=ambiguous_caddy_file count=${#caddy_configs[@]}" >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo 'PUBLIC_URL_FAIL=jq_missing' >&2
  exit 1
fi

caddy validate --config "${caddy_configs[0]}" --adapter caddyfile >/dev/null
mapfile -t public_urls < <(
  caddy adapt --config "${caddy_configs[0]}" --adapter caddyfile 2>/dev/null | jq -r '
    [
      .apps.http.servers[]?
      | (if ((.tls_connection_policies // []) | length) == 0 then "http" else "https" end) as $scheme
      | (.routes // [])
      | ..
      | objects
      | .host? // empty
      | .[]?
      | select(type == "string" and test("^[A-Za-z0-9.-]+$"))
      | "\($scheme)://\(.)"
    ]
    | unique[]
  '
)
if ((${#public_urls[@]} != 1)); then
  echo "PUBLIC_URL_FAIL=ambiguous_caddy_origin count=${#public_urls[@]}" >&2
  exit 1
fi
printf '%s\n' "${public_urls[0]}"
