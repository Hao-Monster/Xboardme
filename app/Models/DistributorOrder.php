<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $order_id
 * @property int $distributor_user_id
 * @property string|null $customer_name
 * @property string|null $remark
 * @property int $subscriber_user_id
 * @property string|null $claim_token
 * @property string $claim_token_hash
 * @property int $delivery_status
 * @property int $settlement_status
 * @property int|null $config_issued_at
 * @property bool $hwid_enabled
 * @property int $hwid_limit
 * @property int|null $connected_at
 * @property int|null $connected_node_id
 * @property string|null $connected_node_name
 * @property int|null $claimed_at
 * @property int|null $closed_at
 * @property int|null $settled_at
 * @property int|null $settled_by
 * @property-read Order $order
 * @property-read User $distributor
 * @property-read User $subscriber
 * @property-read User|null $settledBy
 */
class DistributorOrder extends Model
{
    protected $table = 'v2_distributor_order';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['claim_token', 'claim_token_hash'];
    protected $casts = [
        'claim_token' => 'encrypted',
        'delivery_status' => 'integer',
        'settlement_status' => 'integer',
        'hwid_enabled' => 'boolean',
        'hwid_limit' => 'integer',
        'config_issued_at' => 'timestamp',
        'connected_at' => 'timestamp',
        'claimed_at' => 'timestamp',
        'closed_at' => 'timestamp',
        'settled_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const DELIVERY_PENDING = 0;
    public const DELIVERY_CLAIMED = 1;
    public const DELIVERY_CLOSED = 2;

    public const SETTLEMENT_UNSETTLED = 0;
    public const SETTLEMENT_SETTLED = 1;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_user_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_user_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** @return HasMany<DistributorHwidDevice, $this> */
    public function hwidDevices(): HasMany
    {
        return $this->hasMany(DistributorHwidDevice::class, 'distributor_order_id');
    }

    /** @return HasMany<Order, $this> */
    public function financialOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'distributor_order_id', 'id');
    }
}
