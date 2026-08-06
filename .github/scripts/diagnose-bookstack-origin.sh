#!/usr/bin/env bash
set -Eeuo pipefail

echo '== listeners =='
ss -lntp 2>/dev/null | awk 'NR == 1 || /:80 |:443 |:6875 /' || true

echo '== relevant containers =='
docker ps --format '{{.Names}}\t{{.Image}}\t{{.Ports}}' | grep -Ei 'bookstack|openresty|nginx|caddy|traefik|1panel' || true

echo '== host services =='
for service in nginx openresty caddy; do
  printf '%s: ' "$service"
  systemctl is-active "$service" 2>/dev/null || true
done

echo '== proxy executables =='
for binary in nginx openresty caddy certbot; do
  command -v "$binary" 2>/dev/null || true
done

echo '== likely proxy configuration roots =='
for path in /etc/nginx /etc/openresty /etc/caddy /opt/1panel/apps/openresty /www/server/panel/vhost/nginx; do
  if [[ -d "$path" ]]; then
    printf '%s\n' "$path"
    find "$path" -maxdepth 4 -type f \( -name '*.conf' -o -name 'Caddyfile' \) -print 2>/dev/null | head -n 120
  fi
done

echo '== certificate metadata =='
find /etc/letsencrypt /opt/1panel /www/server/panel -type f \( -name 'fullchain.pem' -o -name '*.crt' -o -name 'cert.pem' \) -print 2>/dev/null | head -n 80 | while IFS= read -r cert; do
  if openssl x509 -in "$cert" -noout >/dev/null 2>&1; then
    printf '%s\t' "$cert"
    openssl x509 -in "$cert" -noout -subject -issuer -dates -ext subjectAltName 2>/dev/null | tr '\n' ' '
    printf '\n'
  fi
done

echo '== local BookStack response =='
curl -sS -o /dev/null -w 'http=%{http_code} redirect=%{redirect_url}\n' --max-time 5 http://127.0.0.1:6875/login || true

echo '== origin TLS response =='
timeout 8 openssl s_client -connect 127.0.0.1:443 -servername docs.thinderbox.com </dev/null 2>/dev/null | openssl x509 -noout -subject -issuer -dates -ext subjectAltName 2>/dev/null || true
