<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_machine_credential', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->unsignedInteger('last_used_at')->nullable();
            $table->unsignedInteger('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('machine_id')
                ->references('id')
                ->on('v2_server_machine')
                ->cascadeOnDelete();
        });

        Schema::create('v2_server_machine_enrollment', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id')->index();
            $table->char('code_hash', 64)->unique();
            $table->boolean('revoke_existing')->default(false);
            $table->unsignedInteger('expires_at')->index();
            $table->unsignedInteger('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('machine_id')
                ->references('id')
                ->on('v2_server_machine')
                ->cascadeOnDelete();
        });

        DB::table('v2_server_machine')
            ->whereNotNull('token')
            ->where('token', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($machines): void {
                foreach ($machines as $machine) {
                    DB::table('v2_server_machine_credential')->insertOrIgnore([
                        'machine_id' => (int) $machine->id,
                        'token_hash' => hash('sha256', (string) $machine->token),
                        'token_prefix' => substr((string) $machine->token, 0, 12),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_machine_enrollment');
        Schema::dropIfExists('v2_server_machine_credential');
    }
};
