<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateConsultationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('clearance_id')->unique();
            $table->text('chiefComplaint');
        //    $table->json('odq')->nullable();
            $table->text('others')->nullable();
            $table->text('vaOD6m')->nullable();
            $table->text('vaOS6m')->nullable();
            $table->text('lidsOD')->nullable();
            $table->text('lidsOS')->nullable();
            $table->text('conjunctivaOD')->nullable();
            $table->text('conjunctivaOS')->nullable();
            $table->text('corneaOD')->nullable();
            $table->text('corneaOS')->nullable();
            $table->text('irisOD')->nullable();
            $table->text('irisOS')->nullable();
            $table->text('pupilOD')->nullable();
            $table->text('pupilOS')->nullable();
            $table->text('lensOD')->nullable();
            $table->text('lensOS')->nullable();
            $table->text('vitreousOD')->nullable();
            $table->text('vitreousOS')->nullable();
            $table->text('fundusOD')->nullable();
            $table->text('fundusOS')->nullable();
            $table->text('cdrOD')->nullable();
            $table->text('cdrOS')->nullable();
          $table->decimal('IOPOD', 8, 2)->nullable(); 
$table->decimal('IOPOS', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('review')->nullable();
            $table->json('prescribed_products')->nullable();
            $table->unsignedBigInteger('drug_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('restrict');
            $table->foreign('clearance_id')->references('id')->on('cashier_patient_clearances')->onDelete('restrict');
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
        Schema::dropIfExists('consultations');
    }
}
