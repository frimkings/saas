<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_lens_blanks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('lens_index', 20);
            $table->string('coating', 80);
            $table->decimal('sphere', 5, 2);
            $table->decimal('cylinder', 5, 2);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reorder_level')->default(5);
            $table->timestamps();
            $table->unique(['clinic_id', 'branch_id', 'lens_index', 'coating', 'sphere', 'cylinder'], 'lens_blanks_location_power_unique');
        });
        Schema::create('optical_lens_blank_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('optical_lens_blank_id')->constrained('optical_lens_blanks')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->integer('quantity_change');
            $table->unsignedInteger('balance_after');
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_lens_blank_movements');
        Schema::dropIfExists('optical_lens_blanks');
    }
};
