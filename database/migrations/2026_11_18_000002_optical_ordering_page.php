<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordering page for partner clinics that are not on Nexus (decided 2026-11-18): the optical centre
 * turns on a private link and sets a PIN; the partner sends orders and follows them there. The
 * requests are ordinary optical_order_requests with no sending clinic (source "page"); if the
 * partner later links on Nexus, they move to its account.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('optical_partner_clinics', 'page_token')) {
                $table->string('page_token', 64)->nullable()->unique()->after('link_status_reason');
                $table->string('page_pin_hash')->nullable()->after('page_token');
                $table->timestamp('page_pin_set_at')->nullable()->after('page_pin_hash');
                $table->unsignedTinyInteger('page_failed_attempts')->default(0)->after('page_pin_set_at');
                $table->timestamp('page_locked_until')->nullable()->after('page_failed_attempts');
                $table->timestamp('page_last_used_at')->nullable()->after('page_locked_until');
            }
        });
        Schema::table('optical_order_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('sender_clinic_id')->nullable()->change();
            if (! Schema::hasColumn('optical_order_requests', 'source')) {
                $table->string('source', 10)->default('link')->after('kind');
                $table->string('sender_name', 120)->nullable()->after('source');
                $table->index(['optical_partner_clinic_id', 'source']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('optical_order_requests', function (Blueprint $table) {
            if (Schema::hasColumn('optical_order_requests', 'source')) {
                $table->dropIndex(['optical_partner_clinic_id', 'source']);
                $table->dropColumn(['source', 'sender_name']);
            }
        });
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            foreach (['page_token', 'page_pin_hash', 'page_pin_set_at', 'page_failed_attempts', 'page_locked_until', 'page_last_used_at'] as $column) {
                if (Schema::hasColumn('optical_partner_clinics', $column)) {
                    if ($column === 'page_token') $table->dropUnique(['page_token']);
                    $table->dropColumn($column);
                }
            }
        });
    }
};
