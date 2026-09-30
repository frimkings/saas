<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Made-to-order products (typically lenses ordered per job from an outside lab) are sold
 * and billed with their cost price but never counted, reserved or taken from stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('made_to_order')->default(false)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('made_to_order');
        });
    }
};
