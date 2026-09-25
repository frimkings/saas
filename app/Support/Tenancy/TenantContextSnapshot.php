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
        ];
    }

    /** Re-authorize the captured membership before a worker uses it. */
    public static function restore(array $snapshot): TenantContext
    {
        $user = User::find($snapshot['user_id'] ?? 0);

        if (! $user) {
            throw new LogicException('The tenant context user no longer exists.');
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
}
