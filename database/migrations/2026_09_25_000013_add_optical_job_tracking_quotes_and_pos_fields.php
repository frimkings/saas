<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optical job tracking (which lab, when sent, when due back, when the status last moved),
 * quotation validity, and the POS customer's phone — plus the shop settings for them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->foreignId('lab_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->timestamp('sent_to_lab_at')->nullable();
            $table->date('expected_back_at')->nullable();
            $table->timestamp('status_changed_at')->nullable()->index();
            $table->date('quote_valid_until')->nullable();
        });
        Schema::table('sales', fn (Blueprint $table) => $table->string('customer_phone', 30)->nullable()->after('customer_name'));
        Schema::table('optical_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('stuck_job_days')->default(7);
            $table->unsignedSmallInteger('quote_validity_days')->default(30);
            $table->unsignedTinyInteger('pos_max_discount_percent')->default(10);
        });

        // Existing jobs: the last update is the best record of when their status last moved.
        DB::table('lens_orders')->whereNull('status_changed_at')->update(['status_changed_at' => DB::raw('updated_at')]);
        // Existing quotations get the default validity from when they were made.
        DB::table('lens_orders')->where('status', 'Quotation')->orderBy('id')->select(['id', 'created_at'])->chunkById(200, function ($quotes) {
            foreach ($quotes as $quote) {
                DB::table('lens_orders')->where('id', $quote->id)->update(['quote_valid_until' => \Illuminate\Support\Carbon::parse($quote->created_at)->addDays(30)->toDateString()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lab_supplier_id');
            $table->dropIndex(['status_changed_at']);
            $table->dropColumn(['sent_to_lab_at', 'expected_back_at', 'status_changed_at', 'quote_valid_until']);
        });
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('customer_phone'));
        Schema::table('optical_settings', fn (Blueprint $table) => $table->dropColumn(['stuck_job_days', 'quote_validity_days', 'pos_max_discount_percent']));
    }
};
