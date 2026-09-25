<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->unsignedBigInteger('consultation_id')->nullable();
            $table->string('transaction_id')->unique(); // New transaction ID field
            $table->decimal('total_amount', 12, 2);
            $table->decimal('profit', 12, 2)->default(0);
            $table->boolean('is_refunded')->default(false);
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedBigInteger('refunded_by')->nullable();
            $table->text('refund_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->foreign('refunded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
             $table->foreign('consultation_id')
                ->references('id')
                ->on('consultations')
                ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales');
    }
};
