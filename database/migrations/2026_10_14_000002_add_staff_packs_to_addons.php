<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add-ons bought in quantity: packs of extra staff on attendance. The platform sets how many
     * staff a pack holds; a clinic's add-on records how many packs it has and the staff they
     * gave when bought, so a later change to the pack size doesn't change what it has.
     */
    public function up(): void
    {
        Schema::table('platform_addons', function (Blueprint $table) {
            $table->unsignedSmallInteger('pack_size')->nullable()->after('is_offered');
        });
        Schema::table('clinic_addons', function (Blueprint $table) {
            $table->unsignedSmallInteger('quantity')->default(1)->after('monthly_price');
            $table->unsignedInteger('units')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_addons', fn (Blueprint $table) => $table->dropColumn(['quantity', 'units']));
        Schema::table('platform_addons', fn (Blueprint $table) => $table->dropColumn('pack_size'));
    }
};
