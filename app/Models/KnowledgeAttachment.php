<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class KnowledgeAttachment extends Model
{
    use SoftDeletes;

    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_READY = 'ready';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'v2_knowledge_attachment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['storage_path', 'draft_token'];
    protected $casts = [
        'size' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'deleted_at' => 'timestamp',
    ];

    protected static function booted(): void
    {
        static::creating(function (KnowledgeAttachment $attachment): void {
            if (!$attachment->uuid) {
                $attachment->uuid = (string) Str::uuid();
            }
        });
    }

    public function knowledge(): BelongsTo
    {
        return $this->belongsTo(Knowledge::class, 'knowledge_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }
}

