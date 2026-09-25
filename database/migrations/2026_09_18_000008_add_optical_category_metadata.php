<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('optical_code', 40)->nullable();
            $table->string('optical_group', 40)->nullable();
            $table->decimal('default_markup', 6, 2)->nullable();
            $table->json('optical_spec')->nullable();
            $table->unique(['clinic_id', 'optical_code']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'optical_code']);
            $table->dropColumn(['optical_code', 'optical_group', 'default_markup', 'optical_spec']);
        });
    }
};
