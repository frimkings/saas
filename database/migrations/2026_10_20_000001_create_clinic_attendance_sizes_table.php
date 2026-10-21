<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance priced by staff size for one clinic: the package, the number of staff it pays for,
 * and the monthly price it was sold at (kept when the price list changes, until the platform
 * reprices it). One row works at a time; a stopped one keeps its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_attendance_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('package', 20);
            // The staff it pays for, which is also the staff limit ("up to" figure, or the agreed number from special pricing).
            $table->unsignedInteger('staff_limit');
            $table->decimal('monthly_price', 10, 2);
            $table->string('currency', 3)->default('GHS');
            // Agreed by hand (special pricing, or a price that isn't the list's).
            $table->boolean('custom_price')->default(false);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['clinic_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_attendance_sizes');
    }
};
