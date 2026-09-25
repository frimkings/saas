<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->string('renewal_approval_status')->nullable()->after('renewal_reminder_sent_at'); // pending|approved|rejected
            $table->foreignId('renewal_approved_by')->nullable()->constrained('users')->nullOnDelete()->after('renewal_approval_status');
            $table->timestamp('renewal_actioned_at')->nullable()->after('renewal_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renewal_approved_by');
            $table->dropColumn(['renewal_approval_status', 'renewal_actioned_at']);
        });
    }
};
