<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->json('examination_assessments')->nullable()->after('odq_details');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', fn (Blueprint $table) => $table->dropColumn('examination_assessments'));
    }
};
