<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agreed terms on a clinic–optical link (decided 2026-11-18):
 *  - trade % off the optical centre's own prices for lenses, frames and services (set by the optical centre),
 *  - a credit limit: at or over it the clinic can't send new jobs (set by the optical centre),
 *  - quotes up to an amount the clinic names are approved automatically and start at once (set by the clinic),
 *  - the platform can pause or end a link with a reason only the platform can lift.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('optical_partner_clinics', 'trade_lens_pct')) {
                $table->decimal('trade_lens_pct', 5, 2)->nullable()->after('link_paused_by_clinic_id');
                $table->decimal('trade_frame_pct', 5, 2)->nullable()->after('trade_lens_pct');
                $table->decimal('trade_service_pct', 5, 2)->nullable()->after('trade_frame_pct');
                $table->decimal('credit_limit', 12, 2)->nullable()->after('trade_service_pct');
                $table->timestamp('terms_updated_at')->nullable()->after('credit_limit');
                $table->decimal('auto_approve_limit', 12, 2)->nullable()->after('terms_updated_at');
                $table->boolean('link_paused_by_platform')->default(false)->after('auto_approve_limit');
                $table->string('link_status_reason', 500)->nullable()->after('link_paused_by_platform');
            }
        });
        Schema::table('optical_order_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('optical_order_requests', 'auto_approved')) {
                $table->boolean('auto_approved')->default(false)->after('approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('optical_order_requests', function (Blueprint $table) {
            if (Schema::hasColumn('optical_order_requests', 'auto_approved')) $table->dropColumn('auto_approved');
        });
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            foreach (['trade_lens_pct', 'trade_frame_pct', 'trade_service_pct', 'credit_limit', 'terms_updated_at', 'auto_approve_limit', 'link_paused_by_platform', 'link_status_reason'] as $column) {
                if (Schema::hasColumn('optical_partner_clinics', $column)) $table->dropColumn($column);
            }
        });
    }
};
