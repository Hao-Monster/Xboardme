#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=/opt/xboard-bookstack
ENV_FILE="$ROOT/.env"
COMPOSE_FILE="$ROOT/compose.yml"
mkdir -p "$ROOT"
chmod 700 "$ROOT"
dump_logs() { docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" logs --tail=160 bookstack db >&2 || true; }
trap dump_logs ERR

if [[ ! -f "$ENV_FILE" ]]; then
  umask 077
  cat > "$ENV_FILE" <<EOF
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 32)
MYSQL_PASSWORD=$(openssl rand -hex 32)
EOF
fi

cat > "$COMPOSE_FILE" <<'EOF'
services:
  db:
    image: mariadb:11.4
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: bookstack
      MYSQL_USER: bookstack
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
    volumes:
      - ./database:/var/lib/mysql
  bookstack:
    image: lscr.io/linuxserver/bookstack:latest
    restart: unless-stopped
    depends_on:
      - db
    ports:
      - "127.0.0.1:6875:80"
    environment:
      PUID: 1000
      PGID: 1000
      TZ: Asia/Singapore
      APP_URL: https://docs.thinderbox.com
      DB_HOST: db
      DB_PORT: 3306
      DB_USERNAME: bookstack
      DB_PASSWORD: ${MYSQL_PASSWORD}
      DB_DATABASE: bookstack
    volumes:
      - ./app:/config
EOF
chmod 600 "$ENV_FILE" "$COMPOSE_FILE"
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d
for _ in $(seq 1 60); do curl -fsS --max-time 3 http://127.0.0.1:6875/login >/dev/null && exit 0; sleep 3; done
echo 'BookStack did not become ready on 127.0.0.1:6875' >&2
dump_logs
exit 1
