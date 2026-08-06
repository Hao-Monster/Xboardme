#!/usr/bin/env bash
set -Eeuo pipefail

CADDY_CONFIG=/etc/caddy/Caddyfile
SITE_HOST=docs.thinderbox.com
UPSTREAM=127.0.0.1:6875
BEGIN_MARKER='# BEGIN XBOARD BOOKSTACK'
END_MARKER='# END XBOARD BOOKSTACK'

if [[ ! -f "$CADDY_CONFIG" ]] || ! command -v caddy >/dev/null 2>&1; then
  echo "Caddy or $CADDY_CONFIG is unavailable" >&2
  exit 1
fi

backup="${CADDY_CONFIG}.bookstack.$(date -u +%Y%m%dT%H%M%SZ).bak"
work="$(mktemp)"
cp -a "$CADDY_CONFIG" "$backup"
rollback() {
  echo 'Caddy configuration failed; restoring the previous file.' >&2
  cp -a "$backup" "$CADDY_CONFIG"
  caddy validate --config "$CADDY_CONFIG" >/dev/null 2>&1 && systemctl reload caddy || true
  rm -f "$work"
}
trap rollback ERR

awk -v begin="$BEGIN_MARKER" -v end="$END_MARKER" '
  $0 == begin { skip = 1; next }
  $0 == end { skip = 0; next }
  !skip { print }
' "$CADDY_CONFIG" > "$work"

cat >> "$work" <<EOF

$BEGIN_MARKER
$SITE_HOST {
	encode zstd gzip
	reverse_proxy $UPSTREAM
}
$END_MARKER
EOF

cat "$work" > "$CADDY_CONFIG"
caddy fmt --overwrite "$CADDY_CONFIG"
caddy validate --config "$CADDY_CONFIG"
systemctl reload caddy
rm -f "$work"
trap - ERR

for _ in $(seq 1 30); do
  if curl --resolve "$SITE_HOST:443:127.0.0.1" -fsS --max-time 5 "https://$SITE_HOST/login" >/dev/null; then
    echo "BookStack is available through Caddy at https://$SITE_HOST/login"
    exit 0
  fi
  sleep 2
done

echo "Caddy was configured, but the public certificate was not ready within 60 seconds." >&2
journalctl -u caddy --since '3 minutes ago' --no-pager | tail -n 120 >&2 || true
exit 1
