<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clinic staff who have never chosen their own password (it was set by an owner, a manager, a CSV
 * file or an import) choose one at their next sign-in, as owners already do. Someone who changed
 * theirs (profile, the forced change screen) is left alone, and platform accounts are not touched.
 * Not undone on rollback: a password chosen since then is the person's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('last_password_changed_at')
            ->where('is_platform_admin', false)
            ->where('must_change_password', false)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('clinic_user')->whereColumn('clinic_user.user_id', 'users.id'))
            ->update(['must_change_password' => true]);
    }

    public function down(): void
    {
        // Nothing to undo safely.
    }
};
