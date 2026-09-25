<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // patients
        Schema::table('patients', function (Blueprint $table) {
            if (!$this->hasIndex('patients', 'patients_user_id_index')) {
                $table->index('user_id');
            }
        });

        // consultations
        Schema::table('consultations', function (Blueprint $table) {
            if (!$this->hasIndex('consultations', 'consultations_user_id_index')) {
                $table->index('user_id');
            }
            if (!$this->hasIndex('consultations', 'consultations_patient_id_index')) {
                $table->index('patient_id');
            }
        });

        // refractions
        Schema::table('refractions', function (Blueprint $table) {
            if (!$this->hasIndex('refractions', 'refractions_user_id_index')) {
                $table->index('user_id');
            }
            if (!$this->hasIndex('refractions', 'refractions_consultation_id_index')) {
                $table->index('consultation_id');
            }
        });

        // appointments
        Schema::table('appointments', function (Blueprint $table) {
            if (!$this->hasIndex('appointments', 'appointments_scheduled_at_index')) {
                $table->index('scheduled_at');
            }
            if (!$this->hasIndex('appointments', 'appointments_status_index')) {
                $table->index('status');
            }
            if (!$this->hasIndex('appointments', 'appointments_scheduled_at_status_index')) {
                $table->index(['scheduled_at', 'status']);
            }
        });

        // sales
        Schema::table('sales', function (Blueprint $table) {
            if (!$this->hasIndex('sales', 'sales_user_id_index')) {
                $table->index('user_id');
            }
            if (!$this->hasIndex('sales', 'sales_patient_id_index')) {
                $table->index('patient_id');
            }
            if (!$this->hasIndex('sales', 'sales_consultation_id_index')) {
                $table->index('consultation_id');
            }
        });

        // sms_logs
        Schema::table('sms_logs', function (Blueprint $table) {
            if (!$this->hasIndex('sms_logs', 'sms_logs_patient_id_index')) {
                $table->index('patient_id');
            }
            if (!$this->hasIndex('sms_logs', 'sms_logs_template_key_index')) {
                $table->index('template_key');
            }
            if (!$this->hasIndex('sms_logs', 'sms_logs_created_at_index')) {
                $table->index('created_at');
            }
        });

        // sms_templates — key column is looked up on every SMS send
        Schema::table('sms_templates', function (Blueprint $table) {
            if (!$this->hasIndex('sms_templates', 'sms_templates_key_index')) {
                $table->index('key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients',      fn (Blueprint $t) => $t->dropIndexIfExists('patients_user_id_index'));
        Schema::table('consultations', fn (Blueprint $t) => $t->dropIndexIfExists(['user_id', 'patient_id']));
        Schema::table('refractions',   fn (Blueprint $t) => $t->dropIndexIfExists(['user_id', 'consultation_id']));
        Schema::table('appointments',  fn (Blueprint $t) => $t->dropIndexIfExists(['scheduled_at', 'status', ['scheduled_at', 'status']]));
        Schema::table('sales',         fn (Blueprint $t) => $t->dropIndexIfExists(['user_id', 'patient_id', 'consultation_id']));
        Schema::table('sms_logs',      fn (Blueprint $t) => $t->dropIndexIfExists(['patient_id', 'template_key', 'created_at']));
        Schema::table('sms_templates', fn (Blueprint $t) => $t->dropIndexIfExists('sms_templates_key_index'));
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($indexName);
    }
};
