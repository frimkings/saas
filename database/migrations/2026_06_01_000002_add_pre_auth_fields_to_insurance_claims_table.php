<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_claims', function (Blueprint $table) {
            $table->enum('pre_auth_status', ['not_required', 'pending', 'approved', 'rejected'])
                  ->default('not_required')->after('notes');
            $table->string('pre_auth_code', 100)->nullable()->after('pre_auth_status');
            $table->decimal('pre_auth_amount', 10, 2)->nullable()->after('pre_auth_code');
            $table->date('pre_auth_date')->nullable()->after('pre_auth_amount');
            $table->date('pre_auth_expiry_date')->nullable()->after('pre_auth_date');
            $table->text('pre_auth_notes')->nullable()->after('pre_auth_expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_claims', function (Blueprint $table) {
            $table->dropColumn([
                'pre_auth_status', 'pre_auth_code', 'pre_auth_amount',
                'pre_auth_date', 'pre_auth_expiry_date', 'pre_auth_notes',
            ]);
        });
    }
};
