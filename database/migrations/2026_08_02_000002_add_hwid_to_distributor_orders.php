<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_distributor_order', function (Blueprint $table) {
            $table->boolean('hwid_enabled')->default(true)->after('customer_name');
            $table->unsignedSmallInteger('hwid_limit')->default(1)->after('hwid_enabled');
            $table->integer('connected_at')->nullable()->after('config_issued_at');
            $table->integer('connected_node_id')->nullable()->after('connected_at');
            $table->string('connected_node_name', 255)->nullable()->after('connected_node_id');
        });

        Schema::create('v2_distributor_hwid_device', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('distributor_order_id')->index();
            $table->string('hwid', 64);
            $table->string('device_os', 100)->nullable();
            $table->string('os_version', 100)->nullable();
            $table->string('device_model', 150)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->integer('first_seen_at');
            $table->integer('last_seen_at');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique(
                ['distributor_order_id', 'hwid'],
                'v2_dist_hwid_order_device_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_distributor_hwid_device');

        Schema::table('v2_distributor_order', function (Blueprint $table) {
            $table->dropColumn([
                'hwid_enabled',
                'hwid_limit',
                'connected_at',
                'connected_node_id',
                'connected_node_name',
            ]);
        });
    }
};
