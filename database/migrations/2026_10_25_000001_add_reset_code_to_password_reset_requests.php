<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approved reset now needs the code the manager was shown on approving it, so typing someone's
 * email is no longer enough to set their password. Approvals made before this have no code: the
 * manager gives a new one from the Password Resets list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->string('code_hash')->nullable()->after('admin_note');
            $table->timestamp('code_expires_at')->nullable()->after('code_hash');
            $table->unsignedTinyInteger('code_attempts')->default(0)->after('code_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropColumn(['code_hash', 'code_expires_at', 'code_attempts']);
        });
    }
};
