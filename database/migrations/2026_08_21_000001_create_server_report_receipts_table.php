<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_report_receipt', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id');
            $table->uuid('report_id');
            $table->string('job_type', 32);
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('created_at')->index();

            $table->unique(
                ['server_id', 'report_id', 'job_type', 'chunk_index'],
                'v2_server_report_receipt_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_report_receipt');
    }
};
