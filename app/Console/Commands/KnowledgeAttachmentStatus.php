<?php

namespace App\Console\Commands;

use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class KnowledgeAttachmentStatus extends Command
{
    protected $signature = 'knowledge-attachments:status {--json : Output machine-readable JSON}';

    protected $description = 'Show knowledge attachment storage health and capacity';

    public function handle(): int
    {
        $quota = max(0, (int) config('knowledge_attachments.total_quota_bytes'));
        $used = (int) KnowledgeAttachment::withTrashed()->sum('size');
        $reserved = (int) KnowledgeAttachmentUpload::whereIn('status', [
            KnowledgeAttachmentUpload::STATUS_INITIALIZED,
            KnowledgeAttachmentUpload::STATUS_UPLOADING,
            KnowledgeAttachmentUpload::STATUS_COMPLETING,
        ])->where('expires_at', '>', time())->sum('declared_size');

        $root = null;
        $readable = false;
        $writable = false;
        $filesystemFree = null;
        $error = null;
        try {
            $root = Storage::disk(config('knowledge_attachments.disk'))->path('');
            $readable = is_dir($root) && is_readable($root);
            $writable = is_dir($root) && is_writable($root);
            $free = is_dir($root) ? @disk_free_space($root) : false;
            $filesystemFree = $free === false ? null : (int) $free;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $healthy = $readable && $writable && $quota > 0 && ($used + $reserved) <= $quota;
        $payload = [
            'healthy' => $healthy,
            'disk' => (string) config('knowledge_attachments.disk'),
            'root' => $root,
            'readable' => $readable,
            'writable' => $writable,
            'used_bytes' => $used,
            'reserved_bytes' => $reserved,
            'quota_bytes' => $quota,
            'quota_available_bytes' => max(0, $quota - $used - $reserved),
            'quota_usage_percent' => $quota > 0 ? round((($used + $reserved) / $quota) * 100, 2) : 100,
            'filesystem_free_bytes' => $filesystemFree,
            'error' => $error,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Metric', 'Value'], [
                ['Health', $healthy ? 'OK' : 'FAILED'],
                ['Disk', $payload['disk']],
                ['Root', $root ?? '-'],
                ['Readable / writable', ($readable ? 'yes' : 'no') . ' / ' . ($writable ? 'yes' : 'no')],
                ['Stored', $this->formatBytes($used)],
                ['Reserved uploads', $this->formatBytes($reserved)],
                ['Application quota', $this->formatBytes($quota)],
                ['Quota available', $this->formatBytes($payload['quota_available_bytes'])],
                ['Filesystem free', $filesystemFree === null ? '-' : $this->formatBytes($filesystemFree)],
            ]);
            if ($error) {
                $this->error($error);
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
    }
}
