<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Knowledge extends Model
{
    protected $table = 'v2_knowledge';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'show' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(KnowledgeAttachment::class, 'knowledge_id');
    }
}
