<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_claims', function (Blueprint $table) {
            // NULL values are excluded from unique checks in MySQL, so multiple
            // claims without a linked sale are still allowed.
            $table->unique('sale_id', 'insurance_claims_sale_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_claims', function (Blueprint $table) {
            $table->dropUnique('insurance_claims_sale_id_unique');
        });
    }
};
