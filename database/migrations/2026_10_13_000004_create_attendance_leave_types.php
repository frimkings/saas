<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A branch's own kinds of leave, each with the working days a person may take in a calendar
     * year (none = no limit) and whether it is paid. A branch that has saved none uses the
     * built-in four. attendance_leaves.type holds the code.
     */
    public function up(): void
    {
        Schema::create('attendance_leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 60);
            $table->decimal('days_per_year', 5, 1)->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leave_types');
    }
};
