<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Clinic and optical keep separate books: an expense belongs to one of them.
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('business_line', 10)->default('clinic')->index();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['business_line']);
            $table->dropColumn('business_line');
        });
    }
};
