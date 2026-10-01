<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insurance co-pay: a bill is split between the patient (pays at the desk) and the
 * insurer (pays later). sales.insurer_amount is the insurer's share; the patient owes
 * total_amount - insurer_amount - amount_paid. Existing sales keep insurer_amount = 0,
 * so nothing already recorded changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            // May the patient be charged the gap between the clinic price and the insurer's tariff?
            $table->boolean('patient_pays_difference')->default(true)->after('active');
            // When the insurer pays less than claimed: bill_patient | write_off.
            $table->string('shortfall_action', 20)->default('bill_patient')->after('patient_pays_difference');
        });

        Schema::create('insurer_coverage_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();
            // Exactly one of category_id / product_id is set; a product rule overrides its category's.
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('coverage_type', 10); // percent | fixed | excluded
            $table->decimal('coverage_value', 12, 2)->default(0);
            $table->decimal('tariff_price', 12, 2)->nullable();
            $table->timestamps();
            $table->index(['insurer_id', 'category_id']);
            $table->index(['insurer_id', 'product_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('insurer_id')->nullable()->after('patient_id')->constrained()->nullOnDelete();
            $table->decimal('insurer_amount', 12, 2)->default(0)->after('amount_paid');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('insurer_amount', 12, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('insurer_amount');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('insurer_id');
            $table->dropColumn('insurer_amount');
        });

        Schema::dropIfExists('insurer_coverage_rules');

        Schema::table('insurers', function (Blueprint $table) {
            $table->dropColumn(['patient_pays_difference', 'shortfall_action']);
        });
    }
};
