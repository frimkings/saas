<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which guided tours (and setup checklists) each person has finished, skipped or hidden, per clinic,
// so a tour starts by itself only once and a person's progress follows them from device to device.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 0 on an offline installation, which has no clinic in the session.
            $table->unsignedBigInteger('clinic_id')->default(0);
            $table->string('guide', 80);
            // A tour whose version has gone up since starts again, for the steps that are new.
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['user_id', 'clinic_id', 'guide']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guides');
    }
};
