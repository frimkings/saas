<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each clinic's own lens designs and treatments, as staff name them. The code is what
     * stock records keep, so renaming changes only the name shown, never the stock.
     */
    public function up(): void
    {
        Schema::create('optical_lens_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('kind', 20); // design | treatment
            $table->string('code', 60);
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['clinic_id', 'kind', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_lens_options');
    }
};
