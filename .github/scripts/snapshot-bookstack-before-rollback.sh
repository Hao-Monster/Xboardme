#!/usr/bin/env bash
set -Eeuo pipefail

stamp=$(date -u +%Y%m%dT%H%M%SZ)
dest="/opt/xboard-bookstack/rollback-backups/$stamp"
mkdir -p "$dest"
chmod 700 /opt/xboard-bookstack/rollback-backups "$dest"

bookstack=$(docker ps -q --filter label=com.docker.compose.project=xboard-bookstack --filter label=com.docker.compose.service=bookstack | head -n 1)
bookstack_db=$(docker ps -q --filter label=com.docker.compose.project=xboard-bookstack --filter label=com.docker.compose.service=db | head -n 1)
xboard=$(docker ps -q --filter label=com.docker.compose.service=xboard | head -n 1)
if [[ -z "$bookstack" || -z "$bookstack_db" || -z "$xboard" ]]; then
  echo 'Required BookStack/Xboard containers were not found.' >&2
  exit 1
fi

docker exec "$bookstack_db" sh -lc 'mariadb-dump --single-transaction -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip -9 > "$dest/bookstack.sql.gz"
tar -czf "$dest/bookstack-app.tar.gz" -C /opt/xboard-bookstack app

docker exec "$xboard" sh -lc 'mkdir -p /www/storage/backup && php /www/artisan backup:database >/tmp/bookstack-rollback-xboard-backup.log 2>&1'
xboard_backup=$(docker exec "$xboard" sh -lc 'ls -1t /www/storage/backup/*.gz | head -n 1')
docker cp "$xboard:$xboard_backup" "$dest/xboard.sql.gz" >/dev/null

docker exec "$xboard" php /www/artisan tinker --execute='$rows=App\Models\Knowledge::query()->orderBy("id")->get(); foreach($rows as $k){echo json_encode(["id"=>$k->id,"title"=>$k->title,"body_bytes"=>strlen((string)$k->body),"body_sha256"=>hash("sha256",(string)$k->body),"attachment_refs"=>substr_count((string)$k->body,"knowledge-attachment://"),"attachment_rows"=>$k->attachments()->count(),"bookstack_page_id"=>$k->bookstack_page_id,"updated_at"=>(string)$k->updated_at],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;}' | tee "$dest/xboard-knowledge-metadata.jsonl"

sha256sum "$dest/bookstack.sql.gz" "$dest/bookstack-app.tar.gz" "$dest/xboard.sql.gz" "$dest/xboard-knowledge-metadata.jsonl" > "$dest/SHA256SUMS"
chmod 600 "$dest"/*
echo "Rollback snapshot completed: $dest"
cat "$dest/SHA256SUMS"
