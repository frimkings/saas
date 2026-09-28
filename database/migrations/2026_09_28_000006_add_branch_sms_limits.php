<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each branch's share of the clinic's SMS (App\Services\Messaging\BranchSmsLimits). The clinic
 * keeps one wallet; a branch with a limit stops sending once it has used it, until the owner
 * adds more. No limit means the branch draws on the wallet freely, as before.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedInteger('sms_limit')->nullable()->after('public_booking_key');
            $table->unsignedInteger('sms_used')->default(0)->after('sms_limit');
            $table->timestamp('sms_warned_at')->nullable()->after('sms_used');
            $table->timestamp('sms_out_at')->nullable()->after('sms_warned_at');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            // SMS parts counted against the branch, returned if the message is never delivered.
            $table->unsignedSmallInteger('branch_counted')->default(0)->after('charged_credits');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['sms_limit', 'sms_used', 'sms_warned_at', 'sms_out_at']);
        });
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropColumn('branch_counted');
        });
    }
};
