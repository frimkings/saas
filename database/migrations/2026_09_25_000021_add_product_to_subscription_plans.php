<?php

use App\Support\PlanProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans say what they cover: the clinic, the optical shop, or both. Existing plans are
 * labelled from their feature list; plans with an empty list include everything (both).
 * Feature lists and subscriptions are not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('product', 20)->default(PlanProduct::BOTH)->after('code')->index();
        });

        DB::table('subscription_plans')->orderBy('id')->each(function ($plan) {
            DB::table('subscription_plans')->where('id', $plan->id)
                ->update(['product' => PlanProduct::productOf(json_decode((string) $plan->features, true) ?: [])]);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropIndex(['product']);
            $table->dropColumn('product');
        });
    }
};
