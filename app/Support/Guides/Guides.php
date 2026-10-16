<?php

namespace App\Support\Guides;

use App\Models\User;
use App\Models\UserGuide;
use App\Support\Tenancy\TenantContext;

/**
 * Whether the signed-in person has been through a guided tour or put a setup checklist away, kept per
 * person and clinic so it follows them to any device. The tours themselves are in TourCatalog.
 */
final class Guides
{
    /** True when the person has finished or skipped this version of the guide (or a later one). */
    public static function seen(string $guide, int $version = 1, ?User $user = null): bool
    {
        $record = self::record($guide, $user);

        return $record !== null && $record->version >= $version;
    }

    public static function record(string $guide, ?User $user = null): ?UserGuide
    {
        $user ??= auth()->user();

        return $user ? UserGuide::query()->where('user_id', $user->id)->where('clinic_id', self::clinicId())->where('guide', $guide)->first() : null;
    }

    public static function mark(string $guide, string $status, int $version = 1, ?User $user = null): void
    {
        $user ??= auth()->user();
        if (! $user) return;
        UserGuide::query()->updateOrCreate(
            ['user_id' => $user->id, 'clinic_id' => self::clinicId(), 'guide' => $guide],
            ['status' => $status, 'version' => $version],
        );
    }

    public static function forget(string $guide, ?User $user = null): void
    {
        $user ??= auth()->user();
        if ($user) UserGuide::query()->where('user_id', $user->id)->where('clinic_id', self::clinicId())->where('guide', $guide)->delete();
    }

    private static function clinicId(): int
    {
        return (int) app(TenantContext::class)->clinicId();
    }
}
