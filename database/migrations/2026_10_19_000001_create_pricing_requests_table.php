<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Request this plan" from the public pricing page: a would-be clinic and the setup it chose. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('clinic_name', 150);
            $table->string('contact_name', 120);
            $table->string('phone', 30);
            $table->string('email', 150)->nullable();
            $table->string('town', 100)->nullable();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('plan_name', 100)->nullable();
            $table->string('billing_interval', 10)->default('monthly');
            // What was ticked (features, users, storage, patients a day, branches) and the quote's lines.
            $table->json('selection')->nullable();
            $table->decimal('monthly_total', 10, 2)->default(0);
            $table->decimal('quoted_total', 10, 2)->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->text('message')->nullable();
            // new → contacted → won (became a clinic) or lost.
            $table->string('status', 20)->default('new')->index();
            $table->text('notes')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_requests');
    }
};
