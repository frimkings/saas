<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linked optical centres: an optical centre invites a clinic on the platform; once linked, the
 * clinic sends spectacle orders (Rx + wearer name/phone) through optical_order_requests.
 * The two new tables sit between clinics, so they carry no tenant scope: every query names the
 * side it reads (optical_clinic_id or sender_clinic_id).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            $table->foreignId('linked_clinic_id')->nullable()->after('clinic_id')->constrained('clinics')->nullOnDelete();
            $table->string('link_status', 20)->nullable()->after('linked_clinic_id');
            $table->timestamp('linked_at')->nullable()->after('link_status');
            // Who paused the link: only that side can resume it.
            $table->unsignedBigInteger('link_paused_by_clinic_id')->nullable()->after('linked_at');
            $table->unique(['clinic_id', 'linked_clinic_id'], 'optical_partner_link_unique');
        });

        Schema::create('optical_link_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optical_clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('optical_partner_clinic_id')->constrained('optical_partner_clinics')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->foreignId('accepted_clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('optical_order_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('sender_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('optical_clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('optical_partner_clinic_id')->constrained('optical_partner_clinics')->cascadeOnDelete();
            $table->unsignedBigInteger('refraction_id')->nullable(); // sender side, for the clinic's own link back
            $table->string('sender_reference')->nullable();
            $table->string('patient_name');
            $table->string('patient_phone', 50)->nullable();
            $table->json('rx');
            $table->string('lens_type')->nullable();
            $table->text('lens_notes')->nullable();
            $table->string('frame_choice', 30)->default('patient_chooses');
            $table->text('frame_notes')->nullable();
            $table->string('urgency', 10)->default('normal');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('sent');
            $table->text('query_message')->nullable();
            $table->text('query_answer')->nullable();
            $table->text('reject_reason')->nullable();
            $table->unsignedBigInteger('lens_order_id')->nullable(); // optical side
            $table->string('order_number')->nullable();
            $table->string('order_status', 40)->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['optical_clinic_id', 'status']);
            $table->index(['sender_clinic_id', 'status']);
            $table->index('refraction_id');
            $table->index('lens_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_order_requests');
        Schema::dropIfExists('optical_link_invites');
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            $table->dropUnique('optical_partner_link_unique');
            $table->dropConstrainedForeignId('linked_clinic_id');
            $table->dropColumn(['link_status', 'linked_at', 'link_paused_by_clinic_id']);
        });
    }
};
