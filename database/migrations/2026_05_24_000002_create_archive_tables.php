<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Archive tables mirror their source schemas but have:
//   - No foreign key constraints (referenced users/patients may be deleted over time)
//   - Only a created_at / login_at index for occasional historical lookups
//   - Original source ID preserved as primary key (not auto-increment) for traceability

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_logs_archive', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('login_at')->nullable();

            $table->index('login_at');
            $table->index('user_id');
        });

        Schema::create('audit_trails_archive', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('event', 80);
            $table->text('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('created_at');
            $table->index('user_id');
        });

        Schema::create('sms_logs_archive', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('template_key')->nullable();
            $table->string('channel', 20)->default('sms');
            $table->string('recipient', 30);
            $table->text('message');
            $table->boolean('success')->default(false);
            $table->string('error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('created_at');
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs_archive');
        Schema::dropIfExists('audit_trails_archive');
        Schema::dropIfExists('sms_logs_archive');
    }
};
