<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('v2_user', 'idx_user_plan_expired_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->index(['plan_id', 'expired_at'], 'idx_user_plan_expired_at');
            });
        }

        if (!Schema::hasIndex('v2_order', 'idx_order_plan_status_type')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->index(['plan_id', 'status', 'type'], 'idx_order_plan_status_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('v2_user', 'idx_user_plan_expired_at')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropIndex('idx_user_plan_expired_at');
            });
        }

        if (Schema::hasIndex('v2_order', 'idx_order_plan_status_type')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->dropIndex('idx_order_plan_status_type');
            });
        }
    }
};
