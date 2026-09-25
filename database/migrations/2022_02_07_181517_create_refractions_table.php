<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRefractionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('refractions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('consultation_id')->unique();
            $table->text('refractionOD');
            $table->text('refractionOS');
            $table->text('notes')->nullable();
            $table->text('lensType')->nullable();
            $table->integer('pd')->nullable();
            $table->text('refractionOD_distance_va');
            $table->text('refractionOD_ADD')->nullable();
            $table->text('refractionOD_near_va')->nullable();
            $table->text('refractionOS_distance_va');
            $table->text('refractionOS_ADD')->nullable();
            $table->text('refractionOS_near_va')->nullable();
            $table->text('refractionnotes')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('consultation_id')->references('id')->on('consultations')->onDelete('restrict');
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
        Schema::dropIfExists('refractions');
    }
}
