<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Insurer remittances: one payment from an insurer, split across the claims it settles.
 * Claims track what has been received and any shortfall (billed to the patient or
 * written off, per the insurer's setting).
 */
return new class extends Migration
{
    private const PERMISSION = 'record insurer payments';

    public function up(): void
    {
        Schema::create('insurer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('insurer_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['insurer_id', 'paid_on']);
        });

        Schema::create('insurer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('insurer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurance_claim_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::table('insurance_claims', function (Blueprint $table) {
            $table->decimal('amount_received', 12, 2)->default(0)->after('approved_amount');
            // The part of the bill the insurer did not pay, and what was done with it.
            $table->decimal('shortfall_amount', 12, 2)->default(0)->after('amount_received');
            $table->string('shortfall_action', 20)->nullable()->after('shortfall_amount');
        });

        $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);
        Role::where('name', 'Manager')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('insurance_claims', function (Blueprint $table) {
            $table->dropColumn(['amount_received', 'shortfall_amount', 'shortfall_action']);
        });
        Schema::dropIfExists('insurer_payment_allocations');
        Schema::dropIfExists('insurer_payments');
    }
};
