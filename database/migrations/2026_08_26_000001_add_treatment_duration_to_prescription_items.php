<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_value')->nullable()->after('frequency');
            $table->string('duration_unit', 20)->nullable()->after('duration_value');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_value')->nullable()->after('frequency');
            $table->string('duration_unit', 20)->nullable()->after('duration_value');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['duration_value', 'duration_unit']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['duration_value', 'duration_unit']);
        });
    }
};
