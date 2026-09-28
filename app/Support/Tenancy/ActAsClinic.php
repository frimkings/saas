<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use App\Services\LicenseService;
use App\Support\Feature;

/**
 * Run work inside a clinic's tenant context from a scheduled job or another clinic's request,
 * then put back whatever context was there before. The context needs a member of staff; the
 * clinic's longest-standing active one is used. $work gets the user, every active branch id,
 * and whether the clinic has the clinic and optical products. Returns false when the clinic
 * has no active branch or staff, so nothing ran.
 */
final class ActAsClinic
{
    public static function run(Clinic $clinic, ?Branch $branch, callable $work): bool
    {
        $context = app(TenantContext::class);
        $saved = $context->resolved() ? [$context->user(), $context->clinic(), $context->branch(), $context->authorizedBranchIds()] : null;
        $branch ??= $clinic->branches()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
        $user = $clinic->users()->wherePivot('status', 'active')->orderBy('users.id')->first();
        if (! $branch || ! $user instanceof User) return false;

        $branchIds = $clinic->branches()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $context->set($user, $clinic, $branch, $branchIds);
        try {
            $work($user, $branchIds, LicenseService::has(Feature::CLINICAL), LicenseService::has(Feature::OPTICAL));
        } finally {
            $saved ? $context->set(...$saved) : $context->clear();
        }

        return true;
    }

    /** Each active branch in turn (only the first without tenancy, where records aren't split by branch). */
    public static function eachBranch(Clinic $clinic, callable $work): void
    {
        $branches = $clinic->branches()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
        if (! config('tenancy.enabled')) $branches = $branches->take(1);

        self::run($clinic, $branches->first(), function (User $user, array $branchIds, bool $clinical, bool $optical) use ($clinic, $branches, $work) {
            foreach ($branches as $branch) {
                app(TenantContext::class)->set($user, $clinic, $branch, $branchIds);
                $work($branch, $clinical, $optical);
            }
        });
    }
}
