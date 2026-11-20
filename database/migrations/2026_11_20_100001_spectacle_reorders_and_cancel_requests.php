<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversing a spectacle order (20 Nov 2026).
 *
 * - A cancelled order keeps its row, and the patient can be ordered again (or sent to an optical centre):
 *   a refraction may now have several orders, only one of them not cancelled (the app checks that).
 * - A clinic can ask a linked optical centre to cancel a job it has started; the centre cancels it or says why not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            // A plain index first, so the foreign key on refraction_id (if any) keeps one when the unique goes.
            $table->index('refraction_id', 'lens_orders_refraction_id_index');
        });
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropUnique('lens_orders_refraction_id_unique');
        });

        Schema::table('optical_order_requests', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('order_cancelled_at');
            $table->string('cancel_request_reason', 500)->nullable()->after('cancel_requested_at');
            $table->foreignId('cancel_requested_by')->nullable()->after('cancel_request_reason')->constrained('users')->nullOnDelete();
            $table->string('cancel_refused_reason', 500)->nullable()->after('cancel_requested_by');
            $table->timestamp('cancel_answered_at')->nullable()->after('cancel_refused_reason');
        });
    }

    public function down(): void
    {
        Schema::table('optical_order_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_requested_by');
            $table->dropColumn(['cancel_requested_at', 'cancel_request_reason', 'cancel_refused_reason', 'cancel_answered_at']);
        });
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->unique('refraction_id', 'lens_orders_refraction_id_unique');
        });
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropIndex('lens_orders_refraction_id_index');
        });
    }
};
