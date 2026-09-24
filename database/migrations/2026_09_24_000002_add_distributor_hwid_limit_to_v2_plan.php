<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->unsignedSmallInteger('distributor_hwid_limit')
                ->default(1)
                ->after('device_limit');
        });
    }

    public function down(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn('distributor_hwid_limit');
        });
    }
};
