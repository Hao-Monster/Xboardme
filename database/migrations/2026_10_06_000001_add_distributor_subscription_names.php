<?php

use App\Services\DistributorSubscriptionNameService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_distributor_order', 'subscription_code')) {
            Schema::table('v2_distributor_order', function (Blueprint $table) {
                $table->string('subscription_code', 6)->nullable();
            });
        }
        if (!Schema::hasColumn('v2_distributor_order', 'subscription_name')) {
            Schema::table('v2_distributor_order', function (Blueprint $table) {
                $table->string('subscription_name', 30)->nullable();
            });
        }
        // MySQL DDL may commit before the migration finishes; repair every missing part on retry.
        if (!Schema::hasIndex('v2_distributor_order', 'v2_dist_subscription_code_unique', 'unique')) {
            Schema::table('v2_distributor_order', function (Blueprint $table) {
                $table->unique('subscription_code', 'v2_dist_subscription_code_unique');
            });
        }

        // Keep historical names stable without changing order numbers, tokens or timestamps.
        // Invalid historical merchant names block rehearsal instead of being silently truncated.
        app(DistributorSubscriptionNameService::class)->backfillPending();
    }

    public function down(): void
    {
        Schema::table('v2_distributor_order', function (Blueprint $table) {
            $table->dropUnique('v2_dist_subscription_code_unique');
            $table->dropColumn(['subscription_code', 'subscription_name']);
        });
    }
};
