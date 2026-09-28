<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid add-ons (App\Services\ClinicAddonService): an extra feature for one clinic on top of its
 * plan, priced per month and charged pro rata when added. Clinics can ask for one; the platform
 * adds it. Invoices get lines so each charge is shown on its own.
 */
return new class extends Migration {
    public function up(): void
    {
        // The price list: which extras clinics can add, and what each costs a month.
        Schema::create('platform_addons', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 60)->unique();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->boolean('is_offered')->default(false);
            $table->timestamps();
        });

        Schema::create('clinic_addon_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 60);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'status']);
        });

        Schema::create('clinic_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 60);
            $table->decimal('monthly_price', 10, 2);
            $table->string('currency', 3)->default('GHS');
            $table->timestamp('starts_at');
            // Set when cancelled: the add-on works until the end of the period already paid for.
            $table->timestamp('ends_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('clinic_addon_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'feature']);
        });

        Schema::create('platform_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->foreignId('clinic_addon_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_lines');
        Schema::dropIfExists('clinic_addons');
        Schema::dropIfExists('clinic_addon_requests');
        Schema::dropIfExists('platform_addons');
    }
};
