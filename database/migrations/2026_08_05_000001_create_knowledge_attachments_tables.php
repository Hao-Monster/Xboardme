<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_knowledge_attachment', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->integer('knowledge_id')->nullable()->index();
            $table->integer('uploader_user_id')->index();
            $table->char('draft_token', 64)->nullable()->index();
            $table->string('original_name', 255);
            $table->string('storage_path', 512);
            $table->string('mime_type', 191)->default('application/octet-stream');
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('status', 32)->index();
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->integer('deleted_at')->nullable()->index();
        });

        Schema::create('v2_knowledge_attachment_upload', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->integer('uploader_user_id')->index();
            $table->char('draft_token', 64)->index();
            $table->string('original_name', 255);
            $table->unsignedBigInteger('declared_size');
            $table->char('expected_sha256', 64)->nullable();
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->string('temporary_path', 512);
            $table->string('status', 32)->index();
            $table->integer('expires_at')->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_knowledge_attachment_upload');
        Schema::dropIfExists('v2_knowledge_attachment');
    }
};
