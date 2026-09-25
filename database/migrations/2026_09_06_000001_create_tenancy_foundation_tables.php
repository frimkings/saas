<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 30)->default('active');
            $table->string('deployment_mode', 30)->default('hosted');
            $table->string('default_timezone', 64)->default('UTC');
            $table->string('default_currency', 10)->default('GH₵');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('contact')->nullable();
            $table->string('email')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('receipt_prefix', 30)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['clinic_id', 'code']);
            $table->index(['clinic_id', 'is_active']);
        });

        Schema::create('clinic_user', function (Blueprint $table) {
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('active');
            $table->string('staff_identifier')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->primary(['clinic_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->primary(['branch_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        $this->bootstrapExistingInstallation();
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('clinic_user');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('clinics');
    }

    private function bootstrapExistingInstallation(): void
    {
        $name = Schema::hasTable('settings')
            ? trim((string) (DB::table('settings')->value('clinic_name') ?? ''))
            : '';

        $name = $name !== '' ? $name : 'My Eye Clinic';
        $clinicUuid = (string) Str::uuid();
        $clinicId = DB::table('clinics')->insertGetId([
            'uuid' => $clinicUuid,
            'name' => $name,
            'slug' => Str::slug($name).'-'.substr($clinicUuid, 0, 8),
            'status' => 'active',
            'deployment_mode' => app()->environment('production') ? 'hosted' : 'local',
            'default_timezone' => (string) config('app.timezone', 'UTC'),
            'default_currency' => 'GH₵',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchId = DB::table('branches')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'clinic_id' => $clinicId,
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'timezone' => (string) config('app.timezone', 'UTC'),
            'receipt_prefix' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('users')) {
            return;
        }

        $timestamp = now();
        DB::table('users')->orderBy('id')->select('id', 'staff_id')->chunkById(500, function ($users) use ($clinicId, $branchId, $timestamp) {
            foreach ($users as $user) {
                DB::table('clinic_user')->insertOrIgnore([
                    'clinic_id' => $clinicId,
                    'user_id' => $user->id,
                    'status' => 'active',
                    'staff_identifier' => $user->staff_id ?? null,
                    'joined_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                DB::table('branch_user')->insertOrIgnore([
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'status' => 'active',
                    'is_default' => true,
                    'joined_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        });
    }
};
