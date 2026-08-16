#!/usr/bin/env bash
set -Eeuo pipefail

readonly DEFAULT_IMAGE="ghcr.io/fenghaoyun-monster/xboardme:distributor"
readonly DEFAULT_INSTALL_DIR="/opt/xboardme"
readonly DEFAULT_PORT="7001"
readonly DEFAULT_ADMIN_ACCOUNT="admin@demo.com"

IMAGE="${XBOARDME_IMAGE:-$DEFAULT_IMAGE}"
INSTALL_DIR="${XBOARDME_DIR:-$DEFAULT_INSTALL_DIR}"
PORT="${XBOARDME_PORT:-$DEFAULT_PORT}"
ADMIN_ACCOUNT="${ADMIN_ACCOUNT:-$DEFAULT_ADMIN_ACCOUNT}"

die() {
  printf 'xboardme: %s\n' "$*" >&2
  exit 1
}

log() {
  printf '\n==> %s\n' "$*"
}

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  die "请使用 root 运行，例如：sudo bash deploy.sh"
fi

command -v docker >/dev/null 2>&1 || die "未检测到 Docker，请先按照 https://docs.docker.com/engine/install/ 安装。"
docker info >/dev/null 2>&1 || die "Docker 服务不可用，请先启动 Docker。"

if docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE=(docker-compose)
else
  die "未检测到 Docker Compose。"
fi

[[ "$ADMIN_ACCOUNT" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] \
  || die "ADMIN_ACCOUNT 必须是有效的邮箱地址。"
[[ "$INSTALL_DIR" == /* && "$INSTALL_DIR" != "/" && "$INSTALL_DIR" != *$'\n'* && "$INSTALL_DIR" != *$'\r'* ]] \
  || die "XBOARDME_DIR 必须是非根目录的绝对路径。"

MANAGED_MARKER="$INSTALL_DIR/.xboardme-managed"
COMPOSE_FILE="$INSTALL_DIR/compose.yaml"
APP_ENV="$INSTALL_DIR/.env"
DEPLOY_ENV="$INSTALL_DIR/.xboardme-deploy.env"

[[ ! -L "$DEPLOY_ENV" ]] || die "拒绝读取符号链接：$DEPLOY_ENV"
if [[ -f "$DEPLOY_ENV" ]]; then
  if [[ -z "${XBOARDME_IMAGE+x}" ]]; then
    saved_image=$(sed -n 's/^XBOARDME_IMAGE=//p' "$DEPLOY_ENV" | tail -n 1)
    [[ -z "$saved_image" ]] || IMAGE="$saved_image"
  fi
  if [[ -z "${XBOARDME_PORT+x}" ]]; then
    saved_port=$(sed -n 's/^XBOARDME_PORT=//p' "$DEPLOY_ENV" | tail -n 1)
    [[ -z "$saved_port" ]] || PORT="$saved_port"
  fi
fi

if [[ ! "$PORT" =~ ^[0-9]+$ ]] || ((PORT < 1 || PORT > 65535)); then
  die "XBOARDME_PORT 必须是 1-65535 之间的端口。"
fi
[[ "$IMAGE" =~ ^[A-Za-z0-9][A-Za-z0-9._/:@-]*$ ]] \
  || die "XBOARDME_IMAGE 格式无效。"

if [[ -d "$INSTALL_DIR" && ! -f "$MANAGED_MARKER" ]]; then
  if find "$INSTALL_DIR" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    die "目录 $INSTALL_DIR 非空且不是本脚本管理的部署，请更换 XBOARDME_DIR。"
  fi
fi

[[ ! -L "$INSTALL_DIR" ]] || die "拒绝使用符号链接作为部署目录：$INSTALL_DIR"

for managed_dir in \
  "$INSTALL_DIR/.docker" \
  "$INSTALL_DIR/.docker/.data" \
  "$INSTALL_DIR/storage" \
  "$INSTALL_DIR/storage/logs" \
  "$INSTALL_DIR/storage/theme" \
  "$INSTALL_DIR/storage/knowledge-attachments" \
  "$INSTALL_DIR/plugins" \
  "$INSTALL_DIR/backups"; do
  [[ ! -L "$managed_dir" ]] || die "拒绝使用符号链接作为持久化目录：$managed_dir"
done

install -d -m 0755 "$INSTALL_DIR"
install -d -m 0755 \
  "$INSTALL_DIR/.docker/.data" \
  "$INSTALL_DIR/storage/logs" \
  "$INSTALL_DIR/storage/theme" \
  "$INSTALL_DIR/storage/knowledge-attachments" \
  "$INSTALL_DIR/plugins"
install -d -m 0700 "$INSTALL_DIR/backups"

for managed_path in "$APP_ENV" "$DEPLOY_ENV" "$COMPOSE_FILE" "$MANAGED_MARKER"; do
  [[ ! -L "$managed_path" ]] || die "拒绝写入符号链接：$managed_path"
done

if [[ ! -e "$APP_ENV" ]]; then
  install -m 0600 /dev/null "$APP_ENV"
elif [[ -d "$APP_ENV" ]]; then
  die "$APP_ENV 必须是文件，不能是目录。"
fi
chown 1000:1000 "$APP_ENV"
chmod 0600 "$APP_ENV"

cat > "$DEPLOY_ENV" <<EOF
XBOARDME_IMAGE=$IMAGE
XBOARDME_PORT=$PORT
EOF
chmod 0600 "$DEPLOY_ENV"

if [[ ! -f "$COMPOSE_FILE" ]]; then
  cat > "$COMPOSE_FILE" <<'YAML'
services:
  xboard:
    image: ${XBOARDME_IMAGE}
    restart: unless-stopped
    ports:
      - "${XBOARDME_PORT}:7001"
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
      - ./storage/knowledge-attachments:/www/storage/app/knowledge-attachments
      - ./plugins:/www/plugins
      - ./backups:/www/storage/backup
      - redis-data:/data
    environment:
      RESOURCE_PROFILE: balanced
      ENABLE_HORIZON: "true"
      KNOWLEDGE_ATTACHMENT_ROOT: /www/storage/app/knowledge-attachments
      docker: "true"

volumes:
  redis-data:
YAML
  chmod 0600 "$COMPOSE_FILE"
fi

touch "$MANAGED_MARKER"
chmod 0600 "$MANAGED_MARKER"

cd "$INSTALL_DIR"
compose() {
  "${COMPOSE[@]}" --env-file "$DEPLOY_ENV" -f "$COMPOSE_FILE" "$@"
}

compose config --quiet

installed=false
if grep -Eq '^[[:space:]]*INSTALLED=(1|true)[[:space:]]*$' "$APP_ENV"; then
  installed=true
fi

if [[ "$installed" == true ]]; then
  container_id=$(compose ps -q xboard 2>/dev/null || true)
  backup_dir="$INSTALL_DIR/backups"
  backup_sentinel=$(mktemp "$backup_dir/.backup-start.XXXXXX")
  trap 'rm -f "$backup_sentinel"' EXIT
  log "升级前备份数据库"
  if [[ -n "$container_id" ]] && [[ "$(docker inspect -f '{{.State.Running}}' "$container_id" 2>/dev/null || true)" == true ]]; then
    compose exec -T xboard php artisan backup:database
  else
    compose run --rm --no-deps --entrypoint php xboard artisan backup:database
  fi
  backup_target=$(find "$backup_dir" -maxdepth 1 -type f -name '*.gz' -newer "$backup_sentinel" -print -quit)
  rm -f "$backup_sentinel"
  trap - EXIT
  [[ -n "$backup_target" ]] || die "数据库备份命令未生成新的备份文件，已停止升级。"
  chmod 0600 "$backup_target"
  log "数据库备份已保存到 $backup_target"
fi

log "拉取最新的 Xboardme 镜像：$IMAGE"
compose pull xboard

if [[ "$installed" == false ]]; then
  log "首次安装（SQLite + 内置 Redis）"
  compose run --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e "ADMIN_ACCOUNT=$ADMIN_ACCOUNT" \
    xboard php artisan xboard:install

  grep -Eq '^[[:space:]]*INSTALLED=(1|true)[[:space:]]*$' "$APP_ENV" \
    || die "安装未完成；请检查上方日志后重新运行脚本。"
fi

log "启动 Xboardme"
compose up -d --remove-orphans

log "执行数据库迁移、插件更新和缓存刷新"
compose exec -T \
  -e CACHE_DRIVER=array \
  -e CACHE_SETTINGS_STORE=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  xboard php artisan xboard:update --no-interaction

log "等待服务就绪"
if ! compose exec -T xboard sh -lc "
  attempt=1
  while [ \"\$attempt\" -le 30 ]; do
    wget -q -O /dev/null http://127.0.0.1:7001/ && exit 0
    attempt=\$((attempt + 1))
    sleep 2
  done
  exit 1
"; then
  compose logs --tail 100 xboard >&2 || true
  die "服务未在 60 秒内就绪，请根据上方日志排查。"
fi

container_id=$(compose ps -q xboard)
running_image=$(docker inspect -f '{{.Config.Image}}' "$container_id")
running_image_id=$(docker inspect -f '{{.Image}}' "$container_id")
expected_image_id=$(docker image inspect -f '{{.Id}}' "$IMAGE")
[[ "$running_image_id" == "$expected_image_id" ]] \
  || die "容器未运行刚刚拉取的最新镜像，已停止并报告失败。"
log "部署完成"
printf '访问地址：http://服务器IP:%s\n' "$PORT"
printf '部署目录：%s\n' "$INSTALL_DIR"
printf '运行镜像：%s\n' "$running_image"
if [[ "$installed" == false ]]; then
  printf '请保存上方首次安装输出中的管理员密码和管理入口。\n'
fi
