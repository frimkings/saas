<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_category_id')->nullable();
            $table->date('expense_date');
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('expense_category_id')->references('id')->on('expense_categories')->onDelete('set null');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('cascade');

            $table->index('expense_date');
            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
