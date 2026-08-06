#!/usr/bin/env bash
set -Eeuo pipefail

xboard=$(docker ps -q --filter label=com.docker.compose.service=xboard | head -n 1)
if [[ -z "$xboard" ]]; then
  echo 'Xboard container was not found.' >&2
  exit 1
fi

docker exec "$xboard" sh -lc 'mkdir -p /www/storage/backup && php /www/artisan backup:database >/tmp/android-knowledge-pre-recovery.log 2>&1'
current_backup=$(docker exec "$xboard" sh -lc 'ls -1t /www/storage/backup/*.gz | head -n 1')
echo "Current database backup created: $current_backup"

docker exec "$xboard" php /www/artisan tinker --execute='$disk=Illuminate\Support\Facades\Storage::disk(config("knowledge_attachments.disk")); $rows=App\Models\Knowledge::query()->orderBy("id")->get(); foreach($rows as $k){$attachments=$k->attachments()->get(); $missing=$attachments->filter(fn($a)=>!$disk->exists($a->storage_path))->count(); echo json_encode(["id"=>$k->id,"title"=>$k->title,"category"=>$k->category,"language"=>$k->language,"show"=>(bool)$k->show,"body_bytes"=>strlen((string)$k->body),"attachment_refs"=>substr_count((string)$k->body,"knowledge-attachment://"),"attachment_rows"=>$attachments->count(),"missing_files"=>$missing,"updated_at"=>(string)$k->updated_at],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;}'

snapshot=/opt/xboard-bookstack/rollback-backups/20260806T033859Z
if [[ -s "$snapshot/SHA256SUMS" ]]; then
  echo "Recovery snapshot available: $snapshot"
  (cd "$snapshot" && sha256sum -c SHA256SUMS)
  echo 'Snapshot knowledge metadata:'
  cat "$snapshot/xboard-knowledge-metadata.jsonl"
else
  echo 'Expected recovery snapshot is unavailable.' >&2
  exit 1
fi
