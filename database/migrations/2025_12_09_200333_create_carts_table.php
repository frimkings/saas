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
        Schema::create('carts', function (Blueprint $table) {
            // Primary Key
            $table->id();
            
            // Foreign Keys
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('dispensed_by');
            $table->unsignedBigInteger('consultation_id')->default(0);
            $table->unsignedBigInteger('product_id');
            
            $table->integer('quantity')->default(1);
            $table->decimal('price', 12, 2);
            $table->decimal('total', 12, 2);
            
            $table->string('frequency', 100)->nullable();
$table->string('eye', 100)->nullable();
            
            // Status Fields
            $table->string('status', 20)->default('pending');
            $table->boolean('is_dispensed')->default(false);
            $table->timestamp('dispensed_at')->nullable();
            $table->boolean('purchased')->default(false);
            
            // Timestamps & Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Key Constraints
            $table->foreign('patient_id')
                ->references('id')
                ->on('patients')
                ->onDelete('cascade');
            
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
            
            $table->foreign('dispensed_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
            
            $table->foreign('consultation_id')
                ->references('id')
                ->on('consultations')
                ->onDelete('cascade');            
            $table->index('patient_id');
            $table->index('consultation_id');
            $table->index('status');
            $table->index('is_dispensed');
            $table->index('purchased');
            $table->index(['patient_id', 'status']); // Composite index for common queries
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('carts');
    }
};