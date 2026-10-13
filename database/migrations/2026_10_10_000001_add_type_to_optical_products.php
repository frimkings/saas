<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every optical product belongs to one of three fixed categories (its `type`): frame,
     * lens or accessory. Products take the type their clinic-named category had, and stock
     * lenses are always lenses. `details` holds what describes a product within its
     * category: a lens's type, form, treatment and manufacturer, or a frame's brand, model,
     * colour and size.
     * The clinic-named categories are no longer used for products; the table and each
     * product's old optical_category_id are left in place so nothing recorded is lost.
     */
    public function up(): void
    {
        Schema::table('optical_products', function (Blueprint $table) {
            $table->string('type', 20)->default('accessory')->after('optical_category_id');
            $table->json('details')->nullable()->after('lens_specs');
            $table->unsignedBigInteger('optical_category_id')->nullable()->change();
        });

        foreach (['frame', 'lens'] as $type) {
            DB::table('optical_products')
                ->whereIn('optical_category_id', DB::table('optical_categories')->where('type', $type)->select('id'))
                ->update(['type' => $type]);
        }
        DB::table('optical_products')->whereNotNull('lens_specs')->update(['type' => 'lens']);
    }

    public function down(): void
    {
        Schema::table('optical_products', fn (Blueprint $table) => $table->dropColumn(['type', 'details']));
    }
};
