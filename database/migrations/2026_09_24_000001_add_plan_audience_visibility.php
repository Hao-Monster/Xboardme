<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->string('customer_visibility', 16)->default('all')->after('sell');
            $table->string('distributor_visibility', 16)->default('all')->after('customer_visibility');
        });

        Schema::create('v2_plan_visibility_user', function (Blueprint $table) {
            $table->integer('plan_id');
            $table->integer('user_id');
            $table->string('audience', 16);
            $table->primary(['plan_id', 'audience', 'user_id'], 'v2_plan_visibility_user_pk');
            $table->index(['user_id', 'audience'], 'v2_plan_visibility_user_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_plan_visibility_user');
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn(['customer_visibility', 'distributor_visibility']);
        });
    }
};
