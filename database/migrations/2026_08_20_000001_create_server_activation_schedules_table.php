<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_activation_schedule', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id')->unique();
            $table->unsignedInteger('enable_at');
            $table->unsignedInteger('disable_at');
            $table->uuid('revision');
            $table->unsignedInteger('enabled_applied_at')->nullable();
            $table->unsignedInteger('disabled_applied_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')
                ->references('id')
                ->on('v2_server')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_activation_schedule');
    }
};
