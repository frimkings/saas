<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic scans, phase 5: result files are patient documents tied to their scan order, and an
 * order remembers how its results were sent to the referring clinic. See docs/diagnostic-scans-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->foreignId('scan_order_id')->nullable()->after('consultation_id')->constrained('scan_orders')->nullOnDelete();
        });

        Schema::table('scan_orders', function (Blueprint $table) {
            $table->string('results_sent_via', 10)->nullable()->after('results_sent_at'); // whatsapp | sms | email | link
            $table->foreignId('results_sent_by')->nullable()->after('results_sent_via')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scan_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('results_sent_by');
            $table->dropColumn('results_sent_via');
        });
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scan_order_id');
        });
    }
};
