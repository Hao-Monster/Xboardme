<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'distributor_name')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('distributor_name', 100)
                    ->nullable()
                    ->after('is_distributor');
            });
        }

        // Existing distributor accounts have no business name yet. Keep them
        // usable after deployment and let an administrator rename them later.
        DB::table('v2_user')
            ->where('is_distributor', true)
            ->where(function ($query) {
                $query->whereNull('distributor_name')
                    ->orWhere('distributor_name', '');
            })
            ->update(['distributor_name' => DB::raw('email')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'distributor_name')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('distributor_name');
            });
        }
    }
};
