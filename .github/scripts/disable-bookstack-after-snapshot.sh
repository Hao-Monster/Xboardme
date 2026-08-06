#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=/opt/xboard-bookstack
CADDY_CONFIG=/etc/caddy/Caddyfile
BEGIN_MARKER='# BEGIN XBOARD BOOKSTACK'
END_MARKER='# END XBOARD BOOKSTACK'

latest=$(find "$ROOT/rollback-backups" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort | tail -n 1)
if [[ -z "$latest" || ! -s "$ROOT/rollback-backups/$latest/SHA256SUMS" ]]; then
  echo 'A verified rollback snapshot is required before BookStack can be disabled.' >&2
  exit 1
fi
(cd "$ROOT/rollback-backups/$latest" && sha256sum -c SHA256SUMS)

if [[ -f "$CADDY_CONFIG" ]]; then
  backup="${CADDY_CONFIG}.pre-bookstack-disable.$(date -u +%Y%m%dT%H%M%SZ).bak"
  work=$(mktemp)
  cp -a "$CADDY_CONFIG" "$backup"
  awk -v begin="$BEGIN_MARKER" -v end="$END_MARKER" '
    $0 == begin { skip = 1; next }
    $0 == end { skip = 0; next }
    !skip { print }
  ' "$CADDY_CONFIG" > "$work"
  cat "$work" > "$CADDY_CONFIG"
  rm -f "$work"
  caddy fmt --overwrite "$CADDY_CONFIG"
  caddy validate --config "$CADDY_CONFIG"
  systemctl reload caddy
fi

if [[ -f "$ROOT/compose.yml" && -f "$ROOT/.env" ]]; then
  docker compose --env-file "$ROOT/.env" -f "$ROOT/compose.yml" down
fi

xboard=$(docker ps -q --filter label=com.docker.compose.service=xboard | head -n 1)
if [[ -n "$xboard" ]]; then
  docker exec "$xboard" php -r '$path="/www/.env"; $content=file_exists($path)?file_get_contents($path):""; $content=preg_replace("/^BOOKSTACK_[A-Z_]+=.*(?:\\R|$)/m","",$content); if(file_put_contents($path,$content)===false){fwrite(STDERR,"Unable to remove BookStack environment values.\n"); exit(1);}'
  docker exec "$xboard" php /www/artisan optimize:clear >/dev/null
fi

if docker ps --format '{{.Label "com.docker.compose.project"}}' | grep -qx 'xboard-bookstack'; then
  echo 'BookStack containers are still running.' >&2
  exit 1
fi

echo 'BookStack routing and containers are disabled; persistent files and rollback backups remain intact.'
