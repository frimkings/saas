<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note on each prescribed drug ("Reduce by one drop a week, then stop") and a taper flag, so a drug that
 * must be tapered is marked on the prescription, the pharmacy counter and the printed slips (24 Nov 2026).
 * sale_items already has a `notes` column for the sale's own status, hence `instructions`.
 */
return new class extends Migration
{
    private const TABLES = ['carts', 'sale_items', 'outside_prescriptions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (!Schema::hasColumn($name, 'instructions')) {
                    $table->string('instructions', 500)->nullable()->after('duration_unit');
                }
                if (!Schema::hasColumn($name, 'taper')) {
                    $table->boolean('taper')->default(false)->after('instructions');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                foreach (['instructions', 'taper'] as $column) {
                    if (Schema::hasColumn($name, $column)) $table->dropColumn($column);
                }
            });
        }
    }
};
