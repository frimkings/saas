<?php

namespace App\Support\Guides;

use App\Models\Consultations;
use App\Models\Diagnosis;
use App\Models\LensOrder;
use App\Models\OpticalProduct;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access\Access;
use App\Support\OpticalMode;
use App\Support\PlanProduct;

/**
 * An optical shop that has just added the clinic to its plan (20 Nov 2026). Instead of being sent to Settings
 * without warning, the owner and managers get a short checklist on their dashboards: add the clinic's starter
 * lists (common diagnoses, product categories), fill in the clinic's details, add a doctor, register the first
 * patient. It lasts until the clinic sees its first patient, or the person hides it.
 */
final class ClinicAdded
{
    public const GUIDE = 'clinic.added';

    /** The account has both modules, the Optical Suite has been in use, and the clinic side has not started yet. */
    public static function pending(): bool
    {
        if (OpticalMode::product() !== PlanProduct::BOTH) return false;
        if (! LensOrder::query()->exists() && ! OpticalProduct::query()->exists()) return false;

        return ! Consultations::query()->exists();
    }

    /** May this person set the clinic up (owner, or a manager on either side)? */
    public static function canSetUp(?User $user): bool
    {
        return Access::allows($user, 'settings.manage') || Access::allows($user, 'dashboard.view') || \App\Support\OpticalAccess::isManager($user);
    }

    /** Someone holds the Doctor role in one of this clinic's branches. */
    private static function hasDoctor(): bool
    {
        $clinicId = app(\App\Support\Tenancy\TenantContext::class)->clinicId();
        $branches = \Illuminate\Support\Facades\DB::table('branches')->when($clinicId, fn ($q) => $q->where('clinic_id', $clinicId))->pluck('id');

        return \Illuminate\Support\Facades\DB::table('branch_user_role')->join('roles', 'roles.id', '=', 'branch_user_role.role_id')
            ->whereIn('branch_user_role.branch_id', $branches)->where('roles.name', 'Doctor')->exists();
    }

    /** The checklist (x-setup-checklist shape), or null when there is nothing to show. */
    public static function for(): ?array
    {
        $user = auth()->user();
        if (! self::canSetUp($user) || ! self::pending() || Guides::seen(self::GUIDE)) return null;
        $route = fn (string $name) => Access::allowsRoute($user, $name) ? route($name) : null;

        $steps = [
            ['key' => 'lists', 'title' => 'Add the clinic\'s starter lists', 'body' => count(\App\Support\ClinicStarterData::DIAGNOSES).' common eye diagnoses for doctors to pick from, and product categories for consultation fees, drugs and drops. Any you already have are skipped.',
                'done' => Diagnosis::query()->exists(), 'action' => 'addClinicStarterLists', 'button' => 'Add them'],
            ['key' => 'details', 'title' => 'Your clinic\'s details', 'body' => 'Address, phone number and the initials patient numbers start with (VS-2611-042). Set the initials before your first patient: they can\'t change after.',
                'done' => ! Setting::getSettings()->needsSetup(), 'url' => $route('admin.settings'), 'button' => 'Settings'],
            ['key' => 'doctor', 'title' => 'Add a doctor', 'body' => 'Invite the doctor (and a secretary or cashier for the front desk). Your optical staff keep their roles.',
                'done' => self::hasDoctor(), 'url' => $route('admin.users'), 'button' => 'Users'],
            ['key' => 'patient', 'title' => 'Register your first patient', 'body' => 'The clinic keeps patient records; your optical customers stay on their orders. Once a patient is seen, the Optical Suite can order straight from their prescription.',
                'done' => Patient::query()->exists(), 'url' => $route('secretary.patients'), 'button' => 'Patients'],
        ];
        $done = count(array_filter($steps, fn ($step) => $step['done']));
        if ($done === count($steps)) return null;

        return ['title' => 'The clinic is now part of your account', 'steps' => $steps, 'extras' => [], 'done' => $done, 'total' => count($steps)];
    }
}
