<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->date('dob')->nullable()->change();
            $table->text('address')->nullable()->change();
        });

        Schema::create('optical_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('refraction_id')->nullable()->constrained('refractions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('source', 30);
            $table->string('prescriber_name')->nullable();
            $table->string('prescriber_clinic')->nullable();
            $table->date('prescribed_at');
            $table->json('measurements');
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id', 'prescribed_at']);
            $table->unique(['clinic_id', 'refraction_id']);
        });

        Schema::table('lens_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('refraction_id')->nullable()->change();
            $table->foreignId('patient_id')->nullable()->after('refraction_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('optical_prescription_id')->nullable()->after('patient_id')->constrained('optical_prescriptions')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->after('optical_prescription_id')->constrained('sales')->nullOnDelete();
            $table->json('prescription_snapshot')->nullable();
            $table->decimal('glazing_fee', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->index(['clinic_id', 'patient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropIndex(['clinic_id', 'patient_id', 'status']);
            $table->dropConstrainedForeignId('sale_id');
            $table->dropConstrainedForeignId('optical_prescription_id');
            $table->dropConstrainedForeignId('patient_id');
            $table->dropColumn(['prescription_snapshot', 'glazing_fee', 'discount_amount']);
        });
        Schema::dropIfExists('optical_prescriptions');
    }
};
