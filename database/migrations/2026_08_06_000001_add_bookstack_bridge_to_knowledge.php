<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_knowledge', function (Blueprint $table) {
            $table->unsignedBigInteger('bookstack_page_id')->nullable()->unique()->after('body');
            $table->string('bookstack_url', 1024)->nullable()->after('bookstack_page_id');
            $table->char('share_token', 64)->nullable()->unique()->after('bookstack_url');
        });
    }
    public function down(): void
    {
        Schema::table('v2_knowledge', function (Blueprint $table) {
            $table->dropUnique(['bookstack_page_id']);
            $table->dropUnique(['share_token']);
            $table->dropColumn(['bookstack_page_id', 'bookstack_url', 'share_token']);
        });
    }
};
