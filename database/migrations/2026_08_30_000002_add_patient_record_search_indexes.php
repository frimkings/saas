<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('diagnoses', fn (Blueprint $table) => $table->index('name', 'diagnoses_name_search_idx'));
        Schema::table('products', function (Blueprint $table) {
            $table->index('name', 'products_name_search_idx');
            $table->index('batch_number', 'products_batch_search_idx');
        });
    }

    public function down(): void
    {
        Schema::table('diagnoses', fn (Blueprint $table) => $table->dropIndex('diagnoses_name_search_idx'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_name_search_idx');
            $table->dropIndex('products_batch_search_idx');
        });
    }
};
