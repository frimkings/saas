<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Needs attention", part two (App\Services\Reminders\AttentionItems):
 *  - orders more than N days late or uncollected stop being chased and go to a tidy-up list;
 *  - stock expiring within N days (or expired) is flagged, for the clinic and the optical shop.
 * Optical stock had no expiry dates, so received optical stock can now carry a batch with one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_stale_days')->default(30);
            $table->unsignedSmallInteger('reminder_expiry_days')->default(90);
        });

        Schema::create('optical_stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('optical_product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('optical_product_stock_movement_id')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date');
            $table->integer('opening_quantity')->default(0);
            $table->integer('quantity')->default(0); // set to 0 when the batch is written off
            $table->timestamps();
            $table->index(['branch_id', 'expiry_date'], 'optical_lots_branch_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_stock_lots');
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn(['reminder_stale_days', 'reminder_expiry_days']));
    }
};
