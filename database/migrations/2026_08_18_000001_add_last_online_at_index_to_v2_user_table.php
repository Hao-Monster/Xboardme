<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->index('last_online_at', 'idx_user_last_online_at');
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex('idx_user_last_online_at');
        });
    }
};
