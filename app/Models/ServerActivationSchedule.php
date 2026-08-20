<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerActivationSchedule extends Model
{
    protected $table = 'v2_server_activation_schedule';

    protected $guarded = ['id'];

    protected $casts = [
        'server_id' => 'integer',
        'enable_at' => 'integer',
        'disable_at' => 'integer',
        'enabled_applied_at' => 'integer',
        'disabled_applied_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
