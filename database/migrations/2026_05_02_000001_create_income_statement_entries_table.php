<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('income_statement_entries', function (Blueprint $table) {
            $table->id();
            $table->string('section', 40);
            $table->string('name');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->date('entry_date');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['section', 'entry_date']);
            $table->index('is_active');
        });
    }

    public function down()
    {
        Schema::dropIfExists('income_statement_entries');
    }
};
