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
            $table->unsignedBigInteger('service_id')->nullable()->after('patient_id');
            $table->foreign('service_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('cashier_patient_clearances', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
    }
};
