<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('cashier_patient_clearances', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_id')->nullable()->after('clearance_date');
            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('cashier_patient_clearances', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->dropColumn('sale_id');
        });
    }
};
