<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'settings', 'sms_logs', 'report_deliveries', 'app_notifications', 'staff_messages',
        'audit_trails', 'login_logs', 'system_health_statuses', 'login_logs_archive',
        'audit_trails_archive', 'sms_logs_archive',
    ];

    public function up(): void
    {
        $clinicId = DB::table('clinics')->orderByRaw("status = 'active' desc")->orderBy('id')->value('id');
        $branchId = DB::table('branches')->where('clinic_id', $clinicId)->orderByDesc('is_default')->orderBy('id')->value('id');

        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('clinic_id')->nullable()->after('id')->constrained()->restrictOnDelete();
                $blueprint->foreignId('branch_id')->nullable()->after('clinic_id')->constrained()->nullOnDelete();
                $blueprint->index(['clinic_id', 'branch_id'], $table.'_tenant_index');
            });
            DB::table($table)->whereNull('clinic_id')->update(['clinic_id' => $clinicId, 'branch_id' => $branchId]);
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->unique('clinic_id', 'settings_clinic_id_unique');
        });
        Schema::table('report_deliveries', function (Blueprint $table) {
            $table->dropUnique('report_deliveries_delivery_key_unique');
            $table->unique(['clinic_id', 'delivery_key']);
        });
        Schema::table('system_health_statuses', function (Blueprint $table) {
            $table->dropUnique('system_health_statuses_key_unique');
            $table->unique(['clinic_id', 'branch_id', 'key'], 'system_health_tenant_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->dropUnique('settings_clinic_id_unique'));
        Schema::table('report_deliveries', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'delivery_key']);
            $table->unique('delivery_key');
        });
        Schema::table('system_health_statuses', function (Blueprint $table) {
            $table->dropUnique('system_health_tenant_key_unique');
            $table->unique('key');
        });
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_tenant_index');
                $blueprint->dropConstrainedForeignId('branch_id');
                $blueprint->dropConstrainedForeignId('clinic_id');
            });
        }
    }
};
