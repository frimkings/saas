<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved password reset also emails the staff member a one-time link to their own inbox,
 * so the approval reaches the person who owns the account, not whoever types the email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->string('link_hash', 64)->nullable()->unique()->after('code_attempts');
            $table->timestamp('link_expires_at')->nullable()->after('link_hash');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropUnique(['link_hash']);
            $table->dropColumn(['link_hash', 'link_expires_at']);
        });
    }
};
