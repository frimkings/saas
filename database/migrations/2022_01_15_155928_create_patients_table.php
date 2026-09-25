<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePatientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('pxnumber')->unique();
            $table->string('name')->index(); 
            $table->date('dob');
            $table->enum('gender', ['Male', 'Female', 'Other']); 
            $table->string('contact');
            $table->string('email')->nullable(); //
            $table->text('address'); 
            $table->string('occupation')->nullable();
            $table->string('civil_status')->nullable(); 
            $table->softDeletes(); 
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('patients');
    }
}