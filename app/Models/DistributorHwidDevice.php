<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributorHwidDevice extends Model
{
    protected $table = 'v2_distributor_hwid_device';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'first_seen_at' => 'timestamp',
        'last_seen_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function distributorOrder(): BelongsTo
    {
        return $this->belongsTo(DistributorOrder::class, 'distributor_order_id');
    }
}
