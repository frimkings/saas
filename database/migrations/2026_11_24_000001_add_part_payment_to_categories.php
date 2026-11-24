<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner's rule per category (2026-11-24): may a registered patient part-pay items of this category at
     * the clinic till, and at least what percent must be paid now (0 = any amount). Frames and lenses start
     * on at 0%, as the till worked before; every other category starts off.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'part_payment')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->boolean('part_payment')->default(false);
                $table->unsignedTinyInteger('part_payment_min_percent')->default(0);
            });
        }

        DB::table('categories')
            ->where(fn ($q) => $q->whereIn('type', ['frame', 'lens'])->orWhere('name', 'like', '%frame%')->orWhere('name', 'like', '%lens%'))
            ->update(['part_payment' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'part_payment')) {
            Schema::table('categories', fn (Blueprint $table) => $table->dropColumn(['part_payment', 'part_payment_min_percent']));
        }
    }
};
