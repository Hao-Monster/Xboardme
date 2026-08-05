<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class KnowledgeAttachmentUploadService
{
    private const QUOTA_LOCK = 'knowledge-attachments:quota';

    public function initialize(
        int $uploaderUserId,
        string $originalName,
        int $declaredSize,
        string $draftToken,
        ?string $expectedSha256 = null
    ): KnowledgeAttachmentUpload {
        $originalName = trim($originalName);
        $expectedSha256 = $expectedSha256 ? strtolower($expectedSha256) : null;
        $chunkSize = (int) config('knowledge_attachments.chunk_size_bytes');
        $maxFileSize = (int) config('knowledge_attachments.max_file_size_bytes');

        if ($chunkSize < 1 || $maxFileSize < 1 || $declaredSize < 1 || $declaredSize > $maxFileSize) {
            throw new ApiException('文件大小超出允许范围。', 422);
        }

        try {
            return Cache::lock(self::QUOTA_LOCK, 15)->block(5, function () use (
                $uploaderUserId,
                $originalName,
                $declaredSize,
                $draftToken,
                $expectedSha256,
                $chunkSize
            ): KnowledgeAttachmentUpload {
                $this->assertQuotaAvailable($declaredSize);

                $totalChunks = (int) ceil($declaredSize / $chunkSize);
                $upload = new KnowledgeAttachmentUpload([
                    'uploader_user_id' => $uploaderUserId,
                    'draft_token' => strtolower($draftToken),
                    'original_name' => $originalName,
                    'declared_size' => $declaredSize,
                    'expected_sha256' => $expectedSha256,
                    'chunk_size' => $chunkSize,
                    'total_chunks' => $totalChunks,
                    'received_chunks' => 0,
                    'status' => KnowledgeAttachmentUpload::STATUS_INITIALIZED,
                    'expires_at' => time() + ((int) config('knowledge_attachments.draft_ttl_hours') * 3600),
                ]);
                $upload->uuid = (string) Str::uuid();
                $upload->temporary_path = $this->temporaryPath($uploaderUserId, $upload->uuid);

                $disk = $this->disk();
                $disk->makeDirectory($upload->temporary_path . '/chunks');

                try {
                    $upload->saveOrFail();
                } catch (Throwable $exception) {
                    $disk->deleteDirectory($upload->temporary_path);
                    throw $exception;
                }

                return $upload;
            });
        } catch (LockTimeoutException) {
            throw new ApiException('上传任务繁忙，请稍后重试。', 423);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Knowledge attachment initialization failed', [
                'uploader_user_id' => $uploaderUserId,
                'error' => $exception->getMessage(),
            ]);
            throw new ApiException('无法创建附件上传任务，请稍后重试。', 500);
        }
    }

    public function storeChunk(
        int $uploaderUserId,
        string $uploadUuid,
        int $index,
        string $expectedChunkSha256,
        UploadedFile $file
    ): array {
        try {
            return Cache::lock($this->uploadLockKey($uploadUuid), 60)->block(5, function () use (
                $uploaderUserId,
                $uploadUuid,
                $index,
                $expectedChunkSha256,
                $file
            ): array {
                $upload = $this->ownedUpload($uploaderUserId, $uploadUuid);
                $this->assertUploadAcceptsChunks($upload);

                if ($index < 0 || $index >= $upload->total_chunks) {
                    throw new ApiException('分片编号超出范围。', 422);
                }
                if (!$file->isValid()) {
                    throw new ApiException('上传的分片无效。', 422);
                }

                $expectedSize = $this->expectedChunkSize($upload, $index);
                $actualSize = (int) $file->getSize();
                if ($actualSize !== $expectedSize) {
                    throw new ApiException('分片大小与上传任务不一致。', 422);
                }

                $expectedChunkSha256 = strtolower($expectedChunkSha256);
                $actualSha256 = hash_file('sha256', $file->getRealPath());
                if (!$actualSha256 || !hash_equals($expectedChunkSha256, $actualSha256)) {
                    throw new ApiException('分片SHA-256校验失败。', 422);
                }

                $disk = $this->disk();
                $chunkPath = $this->chunkPath($upload, $index);
                if ($disk->exists($chunkPath)) {
                    $storedSize = (int) $disk->size($chunkPath);
                    $storedHash = $this->hashStoredFile($disk, $chunkPath);
                    if ($storedSize !== $actualSize || !hash_equals($storedHash, $actualSha256)) {
                        throw new ApiException('相同编号的分片内容不一致。', 409);
                    }

                    return $this->synchronizeChunkState($upload, $index, true);
                }

                $stream = fopen($file->getRealPath(), 'rb');
                if ($stream === false) {
                    throw new ApiException('无法读取上传分片。', 500);
                }

                try {
                    $disk->writeStream($chunkPath, $stream);
                } finally {
                    fclose($stream);
                }

                if (
                    (int) $disk->size($chunkPath) !== $actualSize ||
                    !hash_equals($this->hashStoredFile($disk, $chunkPath), $actualSha256)
                ) {
                    $disk->delete($chunkPath);
                    throw new ApiException('分片落盘校验失败。', 500);
                }

                return $this->synchronizeChunkState($upload, $index, false);
            });
        } catch (LockTimeoutException) {
            throw new ApiException('上传任务正在处理，请稍后重试。', 423);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Knowledge attachment chunk upload failed', [
                'upload_uuid' => $uploadUuid,
                'chunk_index' => $index,
                'error' => $exception->getMessage(),
            ]);
            throw new ApiException('分片保存失败，请稍后重试。', 500);
        }
    }

    public function status(int $uploaderUserId, string $uploadUuid): array
    {
        try {
            return Cache::lock($this->uploadLockKey($uploadUuid), 30)->block(5, function () use (
                $uploaderUserId,
                $uploadUuid
            ): array {
                $upload = $this->ownedUpload($uploaderUserId, $uploadUuid);
                $this->markExpiredWhenNeeded($upload);

                if ($this->canSynchronizeChunks($upload)) {
                    $this->synchronizeChunkState($upload, null, false);
                    $upload->refresh();
                }

                $payload = $this->uploadPayload($upload);
                if ($upload->status === KnowledgeAttachmentUpload::STATUS_COMPLETED) {
                    $attachment = KnowledgeAttachment::withTrashed()->where('uuid', $upload->uuid)->first();
                    if ($attachment && !$attachment->trashed()) {
                        $payload['attachment'] = $this->attachmentPayload($attachment);
                    }
                }

                return $payload;
            });
        } catch (LockTimeoutException) {
            throw new ApiException('上传任务正在处理，请稍后重试。', 423);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Knowledge attachment status query failed', [
                'upload_uuid' => $uploadUuid,
                'error' => $exception->getMessage(),
            ]);
            throw new ApiException('无法查询上传状态，请稍后重试。', 500);
        }
    }

    public function complete(int $uploaderUserId, string $uploadUuid): KnowledgeAttachment
    {
        try {
            return Cache::lock($this->uploadLockKey($uploadUuid), 900)->block(5, function () use (
                $uploaderUserId,
                $uploadUuid
            ): KnowledgeAttachment {
                $upload = $this->ownedUpload($uploaderUserId, $uploadUuid);
                $existing = KnowledgeAttachment::withTrashed()->where('uuid', $upload->uuid)->first();

                if ($upload->status === KnowledgeAttachmentUpload::STATUS_COMPLETED) {
                    if (!$existing || $existing->trashed()) {
                        throw new ApiException('已完成上传对应的附件不可用。', 410);
                    }
                    return $existing;
                }

                $this->assertNotExpired($upload);
                $this->assertAllChunksPresent($upload);
                $upload->status = KnowledgeAttachmentUpload::STATUS_COMPLETING;
                $upload->saveOrFail();

                $disk = $this->disk();
                $workingPath = config('knowledge_attachments.directories.quarantine') . '/' . $upload->uuid . '.part';
                $finalPath = config('knowledge_attachments.directories.files')
                    . '/' . gmdate('Y/m') . '/' . $upload->uuid;

                try {
                    $disk->delete([$workingPath, $finalPath]);
                    $this->assembleChunks($disk, $upload, $workingPath);

                    $assembledSize = (int) $disk->size($workingPath);
                    if ($assembledSize !== $upload->declared_size) {
                        throw new ApiException('合并后的文件大小校验失败。', 422);
                    }

                    $sha256 = $this->hashStoredFile($disk, $workingPath);
                    if (
                        $upload->expected_sha256 &&
                        !hash_equals(strtolower($upload->expected_sha256), $sha256)
                    ) {
                        $this->resetCorruptUpload($upload, $workingPath);
                        throw new ApiException('完整文件SHA-256校验失败，请重新上传。', 422);
                    }

                    $mimeType = $this->detectMimeType($disk, $workingPath);
                    $disk->makeDirectory(dirname($finalPath));
                    $disk->move($workingPath, $finalPath);

                    try {
                        $attachment = DB::transaction(function () use (
                            $upload,
                            $finalPath,
                            $mimeType,
                            $sha256
                        ): KnowledgeAttachment {
                            $attachment = KnowledgeAttachment::where('uuid', $upload->uuid)->first();
                            if (!$attachment) {
                                $attachment = KnowledgeAttachment::create([
                                    'uuid' => $upload->uuid,
                                    'knowledge_id' => null,
                                    'uploader_user_id' => $upload->uploader_user_id,
                                    'draft_token' => $upload->draft_token,
                                    'original_name' => $upload->original_name,
                                    'storage_path' => $finalPath,
                                    'mime_type' => $mimeType,
                                    'extension' => $this->safeExtension($upload->original_name),
                                    'size' => $upload->declared_size,
                                    'sha256' => $sha256,
                                    'status' => KnowledgeAttachment::STATUS_READY,
                                ]);
                            }

                            $upload->status = KnowledgeAttachmentUpload::STATUS_COMPLETED;
                            $upload->received_chunks = $upload->total_chunks;
                            $upload->saveOrFail();

                            return $attachment;
                        });
                    } catch (Throwable $exception) {
                        $disk->delete($finalPath);
                        throw $exception;
                    }

                    try {
                        $disk->deleteDirectory($upload->temporary_path);
                    } catch (Throwable $exception) {
                        Log::warning('Knowledge attachment temporary cleanup failed', [
                            'upload_uuid' => $upload->uuid,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    return $attachment;
                } catch (ApiException $exception) {
                    if ($upload->status === KnowledgeAttachmentUpload::STATUS_COMPLETING) {
                        $upload->status = KnowledgeAttachmentUpload::STATUS_UPLOADING;
                        $upload->save();
                    }
                    throw $exception;
                } catch (Throwable $exception) {
                    $disk->delete([$workingPath, $finalPath]);
                    $upload->status = KnowledgeAttachmentUpload::STATUS_UPLOADING;
                    $upload->save();
                    Log::error('Knowledge attachment completion failed', [
                        'upload_uuid' => $upload->uuid,
                        'error' => $exception->getMessage(),
                    ]);
                    throw new ApiException('附件合并失败，请稍后重试。', 500);
                }
            });
        } catch (LockTimeoutException) {
            throw new ApiException('附件正在合并，请稍后查询状态。', 423);
        }
    }

    public function list(array $filters = []): array
    {
        $query = KnowledgeAttachment::query()->orderByDesc('id');
        if (isset($filters['knowledge_id'])) {
            $query->where('knowledge_id', (int) $filters['knowledge_id']);
        }
        if (!empty($filters['draft_token'])) {
            $query->where('draft_token', strtolower($filters['draft_token']));
        }

        $total = $query->count();
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 50)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $items = $query->forPage($page, $perPage)->get()
            ->map(fn(KnowledgeAttachment $attachment) => $this->attachmentPayload($attachment))
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function delete(string $attachmentUuid): void
    {
        $attachment = KnowledgeAttachment::where('uuid', $attachmentUuid)->first();
        if (!$attachment) {
            throw new ApiException('附件不存在。', 404);
        }
        if ($attachment->knowledge_id !== null) {
            throw new ApiException('附件仍绑定知识文章，请先从文章中移除。', 409);
        }

        $attachment->delete();
    }

    public function uploadPayload(KnowledgeAttachmentUpload $upload): array
    {
        return [
            'upload_uuid' => $upload->uuid,
            'original_name' => $upload->original_name,
            'declared_size' => $upload->declared_size,
            'chunk_size' => $upload->chunk_size,
            'total_chunks' => $upload->total_chunks,
            'received_chunks' => $upload->received_chunks,
            'uploaded_chunks' => $this->uploadedChunkIndexes($upload),
            'status' => $upload->status,
            'expires_at' => (int) $upload->getRawOriginal('expires_at'),
        ];
    }

    public function attachmentPayload(KnowledgeAttachment $attachment): array
    {
        return [
            'uuid' => $attachment->uuid,
            'knowledge_id' => $attachment->knowledge_id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'extension' => $attachment->extension,
            'size' => $attachment->size,
            'sha256' => $attachment->sha256,
            'status' => $attachment->status,
            'created_at' => (int) $attachment->getRawOriginal('created_at'),
        ];
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(config('knowledge_attachments.disk'));
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

    private function ownedUpload(int $uploaderUserId, string $uuid): KnowledgeAttachmentUpload
    {
        $upload = KnowledgeAttachmentUpload::where('uuid', $uuid)
            ->where('uploader_user_id', $uploaderUserId)
            ->first();
        if (!$upload) {
            throw new ApiException('上传任务不存在。', 404);
        }

        return $upload;
    }

    private function assertUploadAcceptsChunks(KnowledgeAttachmentUpload $upload): void
    {
        $this->assertNotExpired($upload);
        if (!in_array($upload->status, [
            KnowledgeAttachmentUpload::STATUS_INITIALIZED,
            KnowledgeAttachmentUpload::STATUS_UPLOADING,
            KnowledgeAttachmentUpload::STATUS_FAILED,
        ], true)) {
            throw new ApiException('当前上传任务不能接收分片。', 409);
        }
    }

    private function assertNotExpired(KnowledgeAttachmentUpload $upload): void
    {
        if ((int) $upload->getRawOriginal('expires_at') <= time()) {
            $upload->status = KnowledgeAttachmentUpload::STATUS_EXPIRED;
            $upload->save();
            throw new ApiException('上传任务已经过期。', 410);
        }
    }

    private function markExpiredWhenNeeded(KnowledgeAttachmentUpload $upload): void
    {
        if (
            $upload->status !== KnowledgeAttachmentUpload::STATUS_COMPLETED &&
            (int) $upload->getRawOriginal('expires_at') <= time()
        ) {
            $upload->status = KnowledgeAttachmentUpload::STATUS_EXPIRED;
            $upload->save();
        }
    }

    private function expectedChunkSize(KnowledgeAttachmentUpload $upload, int $index): int
    {
        if ($index < $upload->total_chunks - 1) {
            return $upload->chunk_size;
        }

        return $upload->declared_size - ($upload->chunk_size * ($upload->total_chunks - 1));
    }

    private function synchronizeChunkState(
        KnowledgeAttachmentUpload $upload,
        ?int $acceptedIndex,
        bool $idempotent
    ): array {
        $indexes = $this->uploadedChunkIndexes($upload);
        $upload->received_chunks = count($indexes);
        if ($this->canSynchronizeChunks($upload)) {
            $upload->status = KnowledgeAttachmentUpload::STATUS_UPLOADING;
        }
        $upload->saveOrFail();

        return [
            'accepted_index' => $acceptedIndex,
            'idempotent' => $idempotent,
            'received_chunks' => $upload->received_chunks,
            'total_chunks' => $upload->total_chunks,
            'uploaded_chunks' => $indexes,
            'ready_to_complete' => $upload->received_chunks === $upload->total_chunks,
        ];
    }

    private function uploadedChunkIndexes(KnowledgeAttachmentUpload $upload): array
    {
        $directory = $upload->temporary_path . '/chunks';
        if (!$this->disk()->directoryExists($directory)) {
            return [];
        }

        $indexes = collect($this->disk()->files($directory))
            ->map(fn(string $path) => basename($path, '.part'))
            ->filter(fn(string $name) => preg_match('/^\\d+$/', $name) === 1)
            ->map(fn(string $name) => (int) $name)
            ->filter(fn(int $index) => $index >= 0 && $index < $upload->total_chunks)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $indexes;
    }

    private function assertAllChunksPresent(KnowledgeAttachmentUpload $upload): void
    {
        $indexes = $this->uploadedChunkIndexes($upload);
        if ($indexes !== range(0, $upload->total_chunks - 1)) {
            throw new ApiException('上传分片尚未完整。', 409);
        }

        foreach ($indexes as $index) {
            if ((int) $this->disk()->size($this->chunkPath($upload, $index)) !== $this->expectedChunkSize($upload, $index)) {
                throw new ApiException('上传分片大小校验失败。', 422);
            }
        }
    }

    private function assembleChunks(
        FilesystemAdapter $disk,
        KnowledgeAttachmentUpload $upload,
        string $workingPath
    ): void {
        $disk->makeDirectory(dirname($workingPath));
        $absoluteWorkingPath = $disk->path($workingPath);
        File::ensureDirectoryExists(dirname($absoluteWorkingPath), 0750, true);
        $output = fopen($absoluteWorkingPath, 'wb');
        if ($output === false) {
            throw new ApiException('无法创建附件合并文件。', 500);
        }

        try {
            for ($index = 0; $index < $upload->total_chunks; $index++) {
                $input = $disk->readStream($this->chunkPath($upload, $index));
                if ($input === false) {
                    throw new ApiException('无法读取上传分片。', 500);
                }
                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new ApiException('合并上传分片失败。', 500);
                    }
                } finally {
                    fclose($input);
                }
            }
            fflush($output);
        } finally {
            fclose($output);
        }
    }

    private function resetCorruptUpload(KnowledgeAttachmentUpload $upload, string $workingPath): void
    {
        $disk = $this->disk();
        $disk->delete($workingPath);
        $disk->deleteDirectory($upload->temporary_path);
        $disk->makeDirectory($upload->temporary_path . '/chunks');
        $upload->status = KnowledgeAttachmentUpload::STATUS_INITIALIZED;
        $upload->received_chunks = 0;
        $upload->save();
    }

    private function detectMimeType(FilesystemAdapter $disk, string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $disk->path($path)) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        return is_string($mimeType) && $mimeType !== ''
            ? strtolower($mimeType)
            : 'application/octet-stream';
    }

    private function hashStoredFile(FilesystemAdapter $disk, string $path): string
    {
        $hash = hash_file('sha256', $disk->path($path));
        if (!$hash) {
            throw new ApiException('无法校验已保存文件。', 500);
        }

        return strtolower($hash);
    }

    private function safeExtension(string $name): ?string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{1,32}$/', $extension) === 1 ? $extension : null;
    }

    private function temporaryPath(int $uploaderUserId, string $uploadUuid): string
    {
        return config('knowledge_attachments.directories.temporary')
            . '/' . $uploaderUserId . '/' . $uploadUuid;
    }

    private function chunkPath(KnowledgeAttachmentUpload $upload, int $index): string
    {
        return $upload->temporary_path . '/chunks/' . $index . '.part';
    }

    private function uploadLockKey(string $uuid): string
    {
        return 'knowledge-attachments:upload:' . $uuid;
    }

    private function canSynchronizeChunks(KnowledgeAttachmentUpload $upload): bool
    {
        return in_array($upload->status, [
            KnowledgeAttachmentUpload::STATUS_INITIALIZED,
            KnowledgeAttachmentUpload::STATUS_UPLOADING,
            KnowledgeAttachmentUpload::STATUS_FAILED,
        ], true);
    }
}
