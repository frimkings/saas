<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refractions', function (Blueprint $table) {
            $table->boolean('dispensing_required')->default(false)->after('subjective_notes');
        });
    }

    public function down(): void
    {
        Schema::table('refractions', function (Blueprint $table) {
            $table->dropColumn('dispensing_required');
        });
    }
};
