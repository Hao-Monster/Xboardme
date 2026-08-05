<?php

namespace App\Services;

use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class KnowledgeAttachmentCleanupService
{
    public function cleanup(): array
    {
        $now = time();
        $draftCutoff = $now - (max(1, (int) config('knowledge_attachments.draft_ttl_hours', 24)) * 3600);
        $trashCutoff = $now - (max(1, (int) config('knowledge_attachments.trash_retention_days', 7)) * 86400);
        $counters = [
            'upload_sessions' => 0,
            'drafts_trashed' => 0,
            'attachments_purged' => 0,
            'orphan_files' => 0,
        ];

        $this->cleanupUploadSessions($draftCutoff, $counters);
        $this->trashStaleDrafts($draftCutoff, $counters);
        $this->purgeExpiredTrash($trashCutoff, $counters);
        $this->cleanupOrphanWorkingFiles($draftCutoff, $counters);

        return $counters;
    }

    private function cleanupUploadSessions(int $draftCutoff, array &$counters): void
    {
        KnowledgeAttachmentUpload::query()
            ->where(function ($query) use ($draftCutoff): void {
                $query->where('expires_at', '<=', time())
                    ->orWhere(function ($completed) use ($draftCutoff): void {
                        $completed->where('status', KnowledgeAttachmentUpload::STATUS_COMPLETED)
                            ->where('updated_at', '<=', $draftCutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($uploads) use (&$counters): void {
                foreach ($uploads as $upload) {
                    try {
                        $this->disk()->deleteDirectory($upload->temporary_path);
                        $upload->delete();
                        $counters['upload_sessions']++;
                    } catch (Throwable $exception) {
                        Log::warning('Knowledge attachment upload cleanup failed', [
                            'upload_uuid' => $upload->uuid,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function trashStaleDrafts(int $draftCutoff, array &$counters): void
    {
        KnowledgeAttachment::query()
            ->whereNull('knowledge_id')
            ->where('created_at', '<=', $draftCutoff)
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use (&$counters): void {
                foreach ($attachments as $attachment) {
                    $attachment->delete();
                    $counters['drafts_trashed']++;
                }
            });
    }

    private function purgeExpiredTrash(int $trashCutoff, array &$counters): void
    {
        KnowledgeAttachment::onlyTrashed()
            ->where('deleted_at', '<=', $trashCutoff)
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use (&$counters): void {
                foreach ($attachments as $attachment) {
                    try {
                        $disk = $this->disk();
                        $disk->delete($attachment->storage_path);
                        if ($disk->exists($attachment->storage_path)) {
                            throw new \RuntimeException('Physical file still exists after delete.');
                        }
                        $attachment->forceDelete();
                        $counters['attachments_purged']++;
                    } catch (Throwable $exception) {
                        Log::warning('Knowledge attachment purge failed', [
                            'attachment_uuid' => $attachment->uuid,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function cleanupOrphanWorkingFiles(int $draftCutoff, array &$counters): void
    {
        $disk = $this->disk();
        $quarantine = (string) config('knowledge_attachments.directories.quarantine');
        $this->deleteOldFiles($disk, $disk->allFiles($quarantine), $draftCutoff, $counters);

        $temporary = (string) config('knowledge_attachments.directories.temporary');
        foreach ($disk->allFiles($temporary) as $path) {
            $parts = explode('/', str_replace('\\', '/', $path));
            if (count($parts) < 4 || $parts[0] !== $temporary) {
                continue;
            }
            $uploadUuid = $parts[2];
            if (KnowledgeAttachmentUpload::where('uuid', $uploadUuid)->exists()) {
                continue;
            }
            $this->deleteOldFiles($disk, [$path], $draftCutoff, $counters);
        }
    }

    private function deleteOldFiles(
        FilesystemAdapter $disk,
        array $paths,
        int $cutoff,
        array &$counters
    ): void {
        foreach ($paths as $path) {
            try {
                if ($disk->lastModified($path) > $cutoff) {
                    continue;
                }
                $disk->delete($path);
                if (!$disk->exists($path)) {
                    $counters['orphan_files']++;
                }
            } catch (Throwable $exception) {
                Log::warning('Knowledge attachment orphan cleanup failed', [
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(config('knowledge_attachments.disk'));
    }
}
