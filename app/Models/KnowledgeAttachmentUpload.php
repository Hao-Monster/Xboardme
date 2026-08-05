<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KnowledgeAttachmentUpload extends Model
{
    public const STATUS_INITIALIZED = 'initialized';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_COMPLETING = 'completing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'v2_knowledge_attachment_upload';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['temporary_path', 'draft_token', 'expected_sha256'];
    protected $casts = [
        'declared_size' => 'integer',
        'chunk_size' => 'integer',
        'total_chunks' => 'integer',
        'received_chunks' => 'integer',
        'expires_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected static function booted(): void
    {
        static::creating(function (KnowledgeAttachmentUpload $upload): void {
            if (!$upload->uuid) {
                $upload->uuid = (string) Str::uuid();
            }
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }
}
