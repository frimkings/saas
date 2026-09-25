<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCashierPatientClearancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cashier_patient_clearances', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('patient_id');

            // Status fields
            $table->enum('payment_status', ['Paid', 'Unpaid'])->default('Unpaid');
            $table->boolean('doctor_status')->default(false);

            // One clearance per patient per day
            $table->date('clearance_date');

            // Foreign keys
            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->onDelete('restrict');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Unique constraint (patient + day)
            $table->unique(['patient_id', 'clearance_date']);

            // Soft deletes & timestamps
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cashier_patient_clearances');
    }
}
