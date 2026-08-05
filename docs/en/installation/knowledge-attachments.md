# Knowledge attachment operations

Knowledge-base attachments are stored outside the public web root and are served only through short-lived signed URLs. The standard container path is `/www/storage/app/knowledge-attachments`; all supplied Compose templates persist it at `./storage/knowledge-attachments` on the host.

Do not expose this directory with Nginx, Caddy, an object-storage public bucket, or a static-file alias. Executable and active-content formats are forced to download by the application, but direct web-server access would bypass those controls.

## Deployment and upgrade

New installations only need the current Compose template. Before starting Xboard, create the host directory with restrictive permissions:

```bash
install -d -m 750 storage/knowledge-attachments
```

The container entrypoint creates `files`, `temporary`, and `quarantine`, assigns them to application uid/gid `1000:1000`, and stops startup if the application user cannot read and write the root.

The repository deployment workflow also supports older Compose projects. On the first upgraded deployment it:

1. Detects whether `/www/storage/app/knowledge-attachments` already has a bind mount or named volume.
2. Reuses an existing mount unchanged.
3. If no mount exists, copies any files from the old container into `storage/knowledge-attachments` before recreation.
4. Adds the persistent mount through the generated deployment override.

After deployment, verify storage health:

```bash
docker compose exec -T xboard php artisan knowledge-attachments:status
```

For monitoring systems, use JSON and treat a non-zero exit status as an alert:

```bash
docker compose exec -T xboard php artisan knowledge-attachments:status --json
```

The command reports stored bytes, bytes reserved by active uploads, application quota, free quota, filesystem free space, and directory readability/writability. Alert before either application quota or filesystem utilization reaches 80%.

## Limits

Default application settings are:

| Setting | Default |
|---|---:|
| Chunk size | 5 MiB |
| Maximum completed file | 1 GiB |
| Total attachment quota | 20 GiB |
| Draft retention | 24 hours |
| Deleted-file retention | 7 days |

They can be adjusted in `.env` through `KNOWLEDGE_ATTACHMENT_CHUNK_SIZE`, `KNOWLEDGE_ATTACHMENT_MAX_FILE_SIZE`, and `KNOWLEDGE_ATTACHMENT_TOTAL_QUOTA`. The container accepts a maximum 16 MiB PHP upload per request and an 18 MiB POST body. Keep the configured chunk size below the PHP request limit; increasing the maximum completed-file size does not require increasing the PHP limit because uploads are chunked.

If `KNOWLEDGE_ATTACHMENT_ROOT` is changed, pass the same absolute path as a container environment variable and mount persistent storage at that exact path. Never set it to `/`, `/www`, `/www/storage`, or `/www/storage/app`.

## Backup

An attachment backup is only useful with the matching database backup because article bodies and attachment metadata contain the UUID mapping. Take both snapshots in the same maintenance window.

Create a protected backup directory:

```bash
install -d -m 700 backups
```

Create the database backup, locate it, and copy it from the container:

```bash
docker compose exec -T xboard php artisan backup:database
```

```bash
DB_BACKUP=$(docker compose exec -T xboard sh -lc 'ls -1t /www/storage/backup/*.gz | head -n 1' | tr -d '\r') && docker compose cp "xboard:$DB_BACKUP" backups/
```

Archive the private attachment directory without following links:

```bash
BACKUP_TIME=$(date -u +%Y%m%dT%H%M%SZ) && tar --numeric-owner --one-file-system -C storage -czf "backups/knowledge-attachments-$BACKUP_TIME.tar.gz" knowledge-attachments
```

Record checksums and store the database archive, attachment archive, `.env`, and checksum file in encrypted off-server storage:

```bash
sha256sum backups/*.gz > backups/SHA256SUMS
```

The example tar command intentionally archives the attachment tree as data and does not dereference symbolic links. Periodically restore a backup into an isolated staging instance; an untested archive is not a recovery plan.

## Restore

Restore only a database and attachment archive from the same backup set. First verify the archive checksums:

```bash
sha256sum -c backups/SHA256SUMS
```

Stop every Xboard process that can upload, bind, clean, or read attachments. For the all-in-one template:

```bash
docker compose stop xboard
```

Keep the current directory as a recoverable rollback copy, then extract the selected archive:

```bash
RESTORE_TIME=$(date -u +%Y%m%dT%H%M%SZ) && mv storage/knowledge-attachments "storage/knowledge-attachments.before-$RESTORE_TIME" && mkdir -p storage && tar --no-same-owner --no-same-permissions -xzf backups/knowledge-attachments-YYYYMMDDTHHMMSSZ.tar.gz -C storage
```

Normalize private permissions using the same image configured by Compose:

```bash
docker compose run --rm --no-deps --entrypoint sh xboard -lc 'chown -R 1000:1000 /www/storage/app/knowledge-attachments && find /www/storage/app/knowledge-attachments -type d -exec chmod 0750 {} + && find /www/storage/app/knowledge-attachments -type f -exec chmod 0640 {} +'
```

Restore the matching database according to the configured database engine, start Xboard, and verify health:

```bash
docker compose up -d && docker compose exec -T xboard php artisan knowledge-attachments:status
```

Confirm several images, videos with seeking, and forced-download attachments from both public and subscription-protected articles before deleting the rollback directory.

## Routine maintenance

Xboard runs `knowledge-attachments:cleanup` hourly. It removes expired upload sessions, expires abandoned drafts, and purges files after the configured soft-delete retention period. It uses a non-overlapping scheduler lock and is safe to invoke manually:

```bash
docker compose exec -T xboard php artisan knowledge-attachments:cleanup
```

Useful host-level checks:

```bash
du -sh storage/knowledge-attachments && df -h storage/knowledge-attachments
```

Backups and `storage/knowledge-attachments` must never be committed to Git or copied into the public web directory.
