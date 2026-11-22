<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform onboarding reused step 2's inputs for step 3, so typing the owner's name and email
 * also overwrote the first branch: name = owner's name, code = owner's email in capitals
 * (e.g. "MR ADAMS" / "ADAMS@GMAIL.COM"). Give those branches code MAIN and, as the platform agreed on
 * 2026-11-22, the clinic's town (from its address); "Main Branch" where no address was filled in.
 * Only codes that are an email are touched, and the name only when it came from the owner fields.
 */
return new class extends Migration
{
    /** Towns from each clinic's address, by slug; Eyes of Hope (set up after the agreed list) by name. */
    private const TOWNS = [
        'vdy-odei' => 'Ayigya',
        'rapha-eye-care' => 'Aboom Wells',
        'myeyedr-vision-and-eyecare-centre' => 'Kwabenya',
        'aped-eye-care' => 'Mankessim',
    ];

    private function townFor(int $clinicId): string
    {
        $clinic = DB::table('clinics')->where('id', $clinicId)->first(['slug', 'name']);
        if ($clinic && isset(self::TOWNS[$clinic->slug])) {
            return self::TOWNS[$clinic->slug];
        }

        return $clinic && preg_match('/\beyes? of hope\b/i', (string) $clinic->name) ? 'Kasoa' : 'Main Branch';
    }

    public function up(): void
    {
        $branches = DB::table('branches')->where('code', 'like', '%@%')->get(['id', 'clinic_id', 'code', 'name']);

        foreach ($branches as $branch) {
            $changes = ['updated_at' => now()];

            if (! DB::table('branches')->where('clinic_id', $branch->clinic_id)->where('code', 'MAIN')->exists()) {
                $changes['code'] = 'MAIN';
            }

            // The name came from the same broken step when the code is a member's email (the owner
            // may have renamed their account since), or when it is a member's name.
            $members = DB::table('clinic_user')->join('users', 'users.id', '=', 'clinic_user.user_id')
                ->where('clinic_user.clinic_id', $branch->clinic_id)->get(['users.name', 'users.email']);
            $ownerTyped = $members->contains(fn ($m) => mb_strtolower(trim((string) $m->email)) === mb_strtolower(trim((string) $branch->code)));
            $memberName = $members->contains(fn ($m) => mb_strtolower(trim((string) $m->name)) === mb_strtolower(trim((string) $branch->name)));
            if ($ownerTyped || $memberName) {
                $changes['name'] = $this->townFor((int) $branch->clinic_id);
            }

            DB::table('branches')->where('id', $branch->id)->update($changes);
        }
    }

    public function down(): void
    {
        // The owner's name and email were never meant to be branch details; nothing to restore.
    }
};
