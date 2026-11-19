<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lens check on the ordering page (19 Nov 2026): a partner clinic checks which lenses fit a
 * prescription and picks one. The optical centre chooses per partner whether prices show
 * (page_show_prices), and the pick travels with the order (lens_choice) and holds the stock
 * lenses until the order is accepted, turned down or cancelled.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('optical_partner_clinics', 'page_show_prices')) {
                $table->boolean('page_show_prices')->default(true)->after('page_last_used_at');
            }
        });
        Schema::table('optical_order_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('optical_order_requests', 'lens_choice')) {
                $table->json('lens_choice')->nullable()->after('lens_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('optical_order_requests', function (Blueprint $table) {
            if (Schema::hasColumn('optical_order_requests', 'lens_choice')) $table->dropColumn('lens_choice');
        });
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            if (Schema::hasColumn('optical_partner_clinics', 'page_show_prices')) $table->dropColumn('page_show_prices');
        });
    }
};
