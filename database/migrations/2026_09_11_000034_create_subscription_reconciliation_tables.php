<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status', 20);
            $table->unsignedInteger('clinics_checked')->default(0);
            $table->unsignedInteger('records_checked')->default(0);
            $table->unsignedInteger('critical_issues')->default(0);
            $table->unsignedInteger('warning_issues')->default(0);
            $table->json('summary')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();
        });

        Schema::create('subscription_reconciliation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('subscription_reconciliation_runs')->cascadeOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->string('code', 60);
            $table->string('severity', 20);
            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('message');
            $table->text('remediation');
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->index(['severity', 'code']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_reconciliation_issues');
        Schema::dropIfExists('subscription_reconciliation_runs');
    }
};
