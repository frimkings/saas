<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic scans, phase 1: the scan catalogue and the partner clinics that refer patients for
 * scans, with their doctors and any commission rates agreed with one partner. Scan orders and
 * commissions come in later phases. See docs/diagnostic-scans-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            // The hidden service product the scan is sold as; filled in once scans are sold (phase 2).
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('price', 12, 2);
            // Priced per eye: one eye is the price, both eyes twice it.
            $table->boolean('per_eye')->default(false);
            $table->string('commission_type', 10)->default('none'); // percent | fixed | none
            $table->decimal('commission_value', 12, 2)->default(0);
            $table->string('turnaround', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['clinic_id', 'name']);
            $table->unique(['clinic_id', 'code']);
        });

        Schema::create('scan_partner_clinics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('code', 20); // RC-001, printed on referral slips
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('notification_phone', 50)->nullable();
            $table->string('notify_via', 20)->default('whatsapp'); // sms | whatsapp | email | none
            $table->string('payout_method', 20)->default('cash'); // cash | mobile_money | bank
            $table->text('payout_account')->nullable(); // encrypted: account or wallet number
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['clinic_id', 'name']);
            $table->unique(['clinic_id', 'code']);
        });

        Schema::create('scan_partner_doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('scan_partner_clinic_id')->constrained('scan_partner_clinics')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['scan_partner_clinic_id', 'name']);
        });

        // A rate agreed with one partner for one scan, instead of the catalogue's.
        Schema::create('scan_partner_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('scan_partner_clinic_id')->constrained('scan_partner_clinics')->cascadeOnDelete();
            $table->foreignId('diagnostic_scan_id')->constrained('diagnostic_scans')->cascadeOnDelete();
            $table->string('commission_type', 10); // percent | fixed | none
            $table->decimal('commission_value', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['scan_partner_clinic_id', 'diagnostic_scan_id'], 'scan_partner_rates_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_partner_rates');
        Schema::dropIfExists('scan_partner_doctors');
        Schema::dropIfExists('scan_partner_clinics');
        Schema::dropIfExists('diagnostic_scans');
    }
};
