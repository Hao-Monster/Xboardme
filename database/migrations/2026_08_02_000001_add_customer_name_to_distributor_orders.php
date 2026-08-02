<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            Schema::hasTable('v2_distributor_order')
            && !Schema::hasColumn('v2_distributor_order', 'customer_name')
        ) {
            Schema::table('v2_distributor_order', function (Blueprint $table) {
                $table->string('customer_name', 64)
                    ->nullable()
                    ->after('distributor_user_id');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('v2_distributor_order')
            && Schema::hasColumn('v2_distributor_order', 'customer_name')
        ) {
            Schema::table('v2_distributor_order', function (Blueprint $table) {
                $table->dropColumn('customer_name');
            });
        }
    }
};
