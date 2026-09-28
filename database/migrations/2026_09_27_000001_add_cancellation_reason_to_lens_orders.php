<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why an order was closed without collection, e.g. a job the customer abandoned. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->string('cancellation_reason', 500)->nullable()->after('cancellation_fee');
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
