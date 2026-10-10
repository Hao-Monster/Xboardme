<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'distributor_revision')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                // Null is the initial version of legacy accounts; no data backfill is needed.
                $table->uuid('distributor_revision')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'distributor_revision')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->dropColumn('distributor_revision');
            });
        }
    }
};
