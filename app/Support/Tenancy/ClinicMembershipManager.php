<?php

namespace App\Support\Tenancy;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class ClinicMembershipManager
{
    public function setDefault(User $user, Clinic|int $clinic): void
    {
        $clinicId = $clinic instanceof Clinic ? $clinic->id : $clinic;
        $allowed = $user->clinics()->whereKey($clinicId)->where('clinics.status', 'active')->wherePivot('status', 'active')->exists();
        if (! $allowed) {
            throw new LogicException('The default clinic must be an active clinic membership.');
        }
        DB::transaction(function () use ($user, $clinicId) {
            DB::table('clinic_user')->where('user_id', $user->id)->update(['is_default' => false]);
            DB::table('clinic_user')->where('user_id', $user->id)->where('clinic_id', $clinicId)->update(['is_default' => true]);
        });
    }

    public function ensureDefault(User $user): ?Clinic
    {
        $active = $user->clinics()->where('clinics.status', 'active')->wherePivot('status', 'active');
        $clinic = (clone $active)->orderByDesc('clinic_user.is_default')->orderBy('clinics.id')->first();
        if ($clinic && ! $clinic->pivot->is_default) {
            $this->setDefault($user, $clinic);
            $clinic->pivot->is_default = true;
        }
        return $clinic;
    }
}
