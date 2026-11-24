<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drug form (24 Nov 2026). Products get the form they come in and, for tablets and capsules, how many are in a
 * strip (they are sold by the strip). Prescription lines keep the form they were prescribed as, the dose and
 * the meal timing, so a tablet line needs no eye and prints "1 tablet · 3 times daily · after meals".
 */
return new class extends Migration
{
    private const LINES = ['carts', 'sale_items', 'outside_prescriptions'];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'dosage_form')) $table->string('dosage_form', 20)->nullable()->after('category_id');
            if (!Schema::hasColumn('products', 'pack_size')) $table->unsignedSmallInteger('pack_size')->nullable()->after('dosage_form');
        });

        foreach (self::LINES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (!Schema::hasColumn($name, 'dosage_form')) $table->string('dosage_form', 20)->nullable()->after('eye');
                if (!Schema::hasColumn($name, 'dose')) $table->decimal('dose', 6, 2)->nullable()->after('dosage_form');
                if (!Schema::hasColumn($name, 'meal_timing')) $table->string('meal_timing', 20)->nullable()->after('dose');
            });
        }
    }

    public function down(): void
    {
        foreach (self::LINES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                foreach (['dosage_form', 'dose', 'meal_timing'] as $column) {
                    if (Schema::hasColumn($name, $column)) $table->dropColumn($column);
                }
            });
        }
        Schema::table('products', function (Blueprint $table) {
            foreach (['dosage_form', 'pack_size'] as $column) {
                if (Schema::hasColumn('products', $column)) $table->dropColumn($column);
            }
        });
    }
};
