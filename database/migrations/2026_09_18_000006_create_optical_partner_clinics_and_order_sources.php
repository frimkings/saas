<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_partner_clinics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('billing_terms', 30)->default('pay_on_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['clinic_id', 'name']);
        });

        Schema::table('lens_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('patient_id')->nullable()->change();
            $table->foreignId('partner_clinic_id')->nullable()->constrained('optical_partner_clinics')->nullOnDelete();
            $table->string('order_source', 20)->default('in_clinic');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('partner_billing_terms', 30)->nullable();
        });

        // Existing free-text partner names remain visible and can be matched in the new registry.
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_clinic_id');
            $table->dropColumn(['order_source', 'customer_name', 'customer_phone', 'partner_billing_terms']);
        });
        Schema::dropIfExists('optical_partner_clinics');
    }
};
