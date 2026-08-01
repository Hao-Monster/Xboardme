<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'is_distributor')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->boolean('is_distributor')
                    ->default(false)
                    ->after('is_staff')
                    ->index();
            });
        }

        if (!Schema::hasTable('v2_distributor_order')) {
            Schema::create('v2_distributor_order', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('order_id')->unique();
                $table->integer('distributor_user_id')->index();
                $table->integer('subscriber_user_id')->unique();
                $table->text('claim_token')->nullable();
                $table->char('claim_token_hash', 64)->unique();
                $table->tinyInteger('delivery_status')->default(0)->index();
                $table->integer('config_issued_at')->nullable();
                $table->integer('claimed_at')->nullable();
                $table->integer('closed_at')->nullable();
                $table->tinyInteger('settlement_status')->default(0)->index();
                $table->integer('settled_at')->nullable();
                $table->integer('settled_by')->nullable();
                $table->string('claim_ip', 45)->nullable();
                $table->string('claim_ua', 255)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->index(
                    ['distributor_user_id', 'settlement_status'],
                    'v2_dist_order_distributor_settlement_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_distributor_order');

        if (Schema::hasColumn('v2_user', 'is_distributor')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropIndex(['is_distributor']);
                $table->dropColumn('is_distributor');
            });
        }
    }
};
