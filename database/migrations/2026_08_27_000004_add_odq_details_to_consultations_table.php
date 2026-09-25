<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('consultations', 'odq_details')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->json('odq_details')->nullable()->after('odq');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consultations', 'odq_details')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->dropColumn('odq_details');
            });
        }
    }
};
