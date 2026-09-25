<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('insurer_id')->nullable()->after('civil_status')
                  ->constrained()->nullOnDelete();
            $table->string('insurance_member_id')->nullable()->after('insurer_id');
            $table->string('insurance_policy_number')->nullable()->after('insurance_member_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropForeign(['insurer_id']);
            $table->dropColumn(['insurer_id', 'insurance_member_id', 'insurance_policy_number']);
        });
    }
};
