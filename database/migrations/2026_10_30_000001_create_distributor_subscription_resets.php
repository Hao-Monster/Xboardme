<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_distributor_subscription_reset', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_order_id');
            $table->unsignedBigInteger('distributor_user_id');
            $table->string('idempotency_key', 64);
            $table->char('previous_version', 64);
            $table->char('credential_version', 64);
            $table->unsignedInteger('cleared_devices');
            $table->unsignedBigInteger('created_at');
            $table->unique(['distributor_order_id', 'idempotency_key'], 'distributor_reset_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_distributor_subscription_reset');
    }
};
