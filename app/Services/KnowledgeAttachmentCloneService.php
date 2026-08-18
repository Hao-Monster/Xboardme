<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class KnowledgeAttachmentCloneService
{
    private const QUOTA_LOCK = 'knowledge-attachments:quota';

    /**
     * @return Collection<int, array{source_uuid:string, attachment:KnowledgeAttachment}>
     */
    public function cloneForDraft(
        int $uploaderUserId,
        int $sourceKnowledgeId,
        array $sourceUuids,
        string $draftToken
    ): Collection {
        $sourceUuids = collect($sourceUuids)
            ->map(fn($uuid) => strtolower(trim((string) $uuid)))
            ->filter()
            ->unique()
            ->values();
        if ($sourceUuids->isEmpty()) {
            throw new ApiException('请选择需要复制的附件。', 422);
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $draftToken)) {
            throw new ApiException('目标文章草稿令牌无效。', 422);
        }
        $maximum = max(1, (int) config('knowledge_attachments.max_attachments_per_article', 100));
        if ($sourceUuids->count() > $maximum) {
            throw new ApiException("单次最多复制 {$maximum} 个附件。", 422);
        }
        if (!Knowledge::query()->whereKey($sourceKnowledgeId)->exists()) {
            throw new ApiException('来源知识文章不存在。', 404);
        }

        try {
            return Cache::lock(self::QUOTA_LOCK, 120)->block(5, function () use (
                $uploaderUserId,
                $sourceKnowledgeId,
                $sourceUuids,
                $draftToken
            ): Collection {
                $sources = KnowledgeAttachment::query()
                    ->where('knowledge_id', $sourceKnowledgeId)
                    ->where('status', KnowledgeAttachment::STATUS_READY)
                    ->whereIn('uuid', $sourceUuids)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn(KnowledgeAttachment $attachment) => strtolower($attachment->uuid));
                if ($sources->count() !== $sourceUuids->count()) {
                    throw new ApiException('来源文章包含不可复制或不存在的附件。', 422);
                }

                $bytes = (int) $sources->sum('size');
                $this->assertQuotaAvailable($bytes);
                $disk = $this->disk();
                $createdPaths = [];

                try {
                    return DB::transaction(function () use (
                        $uploaderUserId,
                        $sourceUuids,
                        $sources,
                        $draftToken,
                        $disk,
                        &$createdPaths
                    ): Collection {
                        return $sourceUuids->map(function (string $sourceUuid) use (
                            $uploaderUserId,
                            $sources,
                            $draftToken,
                            $disk,
                            &$createdPaths
                        ): array {
                            /** @var KnowledgeAttachment $source */
                            $source = $sources->get($sourceUuid);
                            if (!$disk->exists($source->storage_path)) {
                                throw new ApiException('来源附件文件不存在，无法复制。', 410);
                            }
                            if (!hash_equals(strtolower($source->sha256), $this->storedHash($disk, $source->storage_path))) {
                                throw new ApiException('来源附件完整性校验失败。', 422);
                            }

                            $uuid = (string) Str::uuid();
                            $path = config('knowledge_attachments.directories.files')
                                . '/' . gmdate('Y/m') . '/' . $uuid;
                            $disk->makeDirectory(dirname($path));
                            if (!$disk->copy($source->storage_path, $path)) {
                                throw new ApiException('附件文件复制失败，请稍后重试。', 500);
                            }
                            $createdPaths[] = $path;
                            if (
                                (int) $disk->size($path) !== (int) $source->size
                                || !hash_equals(strtolower($source->sha256), $this->storedHash($disk, $path))
                            ) {
                                throw new ApiException('附件副本完整性校验失败。', 500);
                            }

                            $clone = KnowledgeAttachment::create([
                                'uuid' => $uuid,
                                'knowledge_id' => null,
                                'uploader_user_id' => $uploaderUserId,
                                'draft_token' => strtolower($draftToken),
                                'original_name' => $source->original_name,
                                'storage_path' => $path,
                                'mime_type' => $source->mime_type,
                                'extension' => $source->extension,
                                'size' => $source->size,
                                'sha256' => $source->sha256,
                                'status' => KnowledgeAttachment::STATUS_READY,
                            ]);

                            return ['source_uuid' => $sourceUuid, 'attachment' => $clone];
                        });
                    }, 3);
                } catch (Throwable $exception) {
                    $disk->delete($createdPaths);
                    throw $exception;
                }
            });
        } catch (LockTimeoutException) {
            throw new ApiException('附件复制任务繁忙，请稍后重试。', 423);
        }
    }

    private function assertQuotaAvailable(int $newSize): void
    {
        $used = (int) KnowledgeAttachment::withTrashed()->sum('size');
        $reserved = (int) KnowledgeAttachmentUpload::whereIn('status', [
            KnowledgeAttachmentUpload::STATUS_INITIALIZED,
            KnowledgeAttachmentUpload::STATUS_UPLOADING,
            KnowledgeAttachmentUpload::STATUS_COMPLETING,
        ])->where('expires_at', '>', time())->sum('declared_size');
        $quota = (int) config('knowledge_attachments.total_quota_bytes');

        if ($quota < 1 || $newSize > ($quota - $used - $reserved)) {
            throw new ApiException('知识库附件存储空间不足。', 422);
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(config('knowledge_attachments.disk'));
    }

    private function storedHash(FilesystemAdapter $disk, string $path): string
    {
        $hash = hash_file('sha256', $disk->path($path));
        if (!$hash) {
            throw new ApiException('无法校验附件文件。', 500);
        }

        return strtolower($hash);
    }
}
