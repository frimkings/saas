<?php

namespace App\Support\Tenancy;

use App\Models\User;
use LogicException;

class TenantContextSnapshot
{
    /** Capture only server-resolved IDs; never accept tenant IDs from job arguments. */
    public static function capture(): ?array
    {
        $context = app(TenantContext::class);

        if (! $context->resolved()) {
            return null;
        }

        return [
            'user_id' => $context->user()?->id,
            'clinic_id' => $context->clinicId(),
            'branch_id' => $context->branchId(),
            // A platform admin inspecting the clinic has no membership there; say so, so the worker
            // checks the admin instead of a membership that does not exist.
            'support' => self::supportSessionFor($context->clinicId()),
        ];
    }

    /** Re-authorize the captured membership before a worker uses it. */
    public static function restore(array $snapshot): TenantContext
    {
        $user = User::find($snapshot['user_id'] ?? 0);

        if (! $user) {
            throw new LogicException('The tenant context user no longer exists.');
        }

        if (! empty($snapshot['support'])) {
            return self::restoreSupport($user, $snapshot);
        }

        $clinic = $user->clinics()
            ->whereKey($snapshot['clinic_id'] ?? 0)
            ->where('clinics.status', 'active')
            ->wherePivot('status', 'active')
            ->first();

        if (! $clinic) {
            throw new LogicException('The queued clinic context is no longer authorized.');
        }

        $branches = $user->branches()
            ->where('branches.clinic_id', $clinic->id)
            ->where('branches.is_active', true)
            ->wherePivot('status', 'active');
        $ids = (clone $branches)->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        $branch = $branches->whereKey($snapshot['branch_id'] ?? 0)->first();

        if (! $branch) {
            throw new LogicException('The queued branch context is no longer authorized.');
        }

        $context = app(TenantContext::class);
        $context->clear();
        $context->set($user, $clinic, $branch, $ids);
        app(BranchRoleManager::class)->hydrate($user, $branch);

        return $context;
    }

    private static function supportSessionFor(?int $clinicId): bool
    {
        try {
            return $clinicId !== null && \App\Support\SupportSession::clinicId() === $clinicId;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A support session: still a platform admin, and the clinic and branch are still open. */
    private static function restoreSupport(User $user, array $snapshot): TenantContext
    {
        if (! $user->is_platform_admin) {
            throw new LogicException('The queued support context is no longer authorized.');
        }
        $clinic = \App\Models\Clinic::where('status', 'active')->find($snapshot['clinic_id'] ?? 0);
        $branch = $clinic?->branches()->where('is_active', true)->whereKey($snapshot['branch_id'] ?? 0)->first();
        if (! $clinic || ! $branch) {
            throw new LogicException('The queued clinic context is no longer authorized.');
        }

        $context = app(TenantContext::class);
        $context->clear();
        $context->set($user, $clinic, $branch, $clinic->branches()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $user->setRelation('roles', collect([\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])]));

        return $context;
    }
}
