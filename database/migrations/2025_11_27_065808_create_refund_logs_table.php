<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up()
    {
        Schema::create('refund_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sale_id');

            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();

            $table->text('reason')->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->foreign('sale_id')
                ->references('id')->on('sales')
                ->cascadeOnDelete();

            $table->foreign('initiated_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreign('approved_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreign('processed_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('refund_logs');
    }
};
