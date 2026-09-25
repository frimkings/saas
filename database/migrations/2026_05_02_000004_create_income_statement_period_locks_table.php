<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('income_statement_period_locks', function (Blueprint $table) {
            $table->id();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('locked_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['from_date', 'to_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('income_statement_period_locks');
    }
};
