<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Location link (e.g. Google Maps) used by the [LINK] message placeholder.
        Schema::table('settings', function (Blueprint $table) {
            $table->string('clinic_link', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('clinic_link');
        });
    }
};
