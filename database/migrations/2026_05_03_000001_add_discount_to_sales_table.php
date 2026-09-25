<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('discount_type')->nullable()->after('profit');   // 'percentage' | 'fixed'
            $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');  // the raw input (% or ₵)
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_value'); // computed ₵ discount
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
        });
    }
};
