<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_activation_schedule', function (Blueprint $table) {
            $table->string('schedule_type', 16)->default('once');
            $table->string('timezone', 64)->nullable();
            $table->unsignedInteger('enable_second')->nullable();
            $table->unsignedInteger('disable_second')->nullable();
            $table->unsignedInteger('next_transition_at')->nullable();
            $table->boolean('next_target_enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_activation_schedule', function (Blueprint $table) {
            $table->dropColumn([
                'schedule_type',
                'timezone',
                'enable_second',
                'disable_second',
                'next_transition_at',
                'next_target_enabled',
            ]);
        });
    }
};
