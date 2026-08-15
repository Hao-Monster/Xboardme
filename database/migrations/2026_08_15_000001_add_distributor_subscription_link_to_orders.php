<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->integer('distributor_order_id')->nullable();
            $table->integer('entitlement_expired_at_before')->nullable();
            $table->integer('entitlement_expired_at_after')->nullable();
            $table->char('distributor_idempotency_key', 36)->nullable();
            $table->integer('distributor_settled_by')->nullable();

            $table->index('distributor_order_id', 'v2_order_distributor_order_idx');
            $table->unique(
                ['user_id', 'distributor_idempotency_key'],
                'v2_order_dist_idempotency_unique'
            );
            $table->index(
                ['user_id', 'distributor_order_id', 'status', 'paid_at'],
                'v2_order_dist_settlement_idx'
            );
        });

        // Existing distributor rows represent the original purchase. Link those
        // financial orders to their stable subscription record without changing
        // the subscriber, token, delivery or HWID state.
        DB::table('v2_distributor_order')
            ->orderBy('id')
            ->chunkById(200, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $updates = [
                        'distributor_order_id' => (int) $delivery->id,
                    ];

                    if (
                        (int) $delivery->settlement_status === 1
                        && $delivery->settled_by !== null
                    ) {
                        $updates['distributor_settled_by'] = (int) $delivery->settled_by;
                    }

                    $order = DB::table('v2_order')
                        ->where('id', (int) $delivery->order_id)
                        ->first(['paid_at']);
                    if (
                        $order
                        && $order->paid_at === null
                        && (int) $delivery->settlement_status === 1
                    ) {
                        $updates['paid_at'] = (int) (
                            $delivery->settled_at
                            ?: $delivery->updated_at
                            ?: time()
                        );
                    }

                    DB::table('v2_order')
                        ->where('id', (int) $delivery->order_id)
                        ->update($updates);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropIndex('v2_order_dist_settlement_idx');
            $table->dropUnique('v2_order_dist_idempotency_unique');
            $table->dropIndex('v2_order_distributor_order_idx');
            $table->dropColumn([
                'distributor_order_id',
                'entitlement_expired_at_before',
                'entitlement_expired_at_after',
                'distributor_idempotency_key',
                'distributor_settled_by',
            ]);
        });
    }
};
