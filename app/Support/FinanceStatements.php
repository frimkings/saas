<?php

namespace App\Support;

use App\Models\User;
use App\Services\ClinicAccessService;
use App\Services\LicenseService;

/**
 * Which financial statements a user can open: the clinic income statement, the optical
 * Profit & Loss, and — for subscribers running both businesses — the combined view.
 */
class FinanceStatements
{
    public static function hasBothLines(): bool
    {
        return count(self::availableLines()) === 2;
    }

    /** The business lines this subscriber runs. */
    public static function availableLines(): array
    {
        $access = app(ClinicAccessService::class);
        $lines = [];
        if (! OpticalMode::opticalOnly() && $access->access('clinical')['allowed']) $lines[] = BusinessLine::CLINIC;
        if ($access->access('optical')['allowed']) $lines[] = BusinessLine::OPTICAL;

        return $lines;
    }

    public static function canViewClinic(?User $user): bool
    {
        return $user !== null && LicenseService::has(Feature::ADVANCED_REPORTS)
            && ($user->hasRole('Super Admin') || $user->can('manage billing'));
    }

    public static function canViewOptical(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(['Manager', 'Super Admin'])
            && app(ClinicAccessService::class)->access('optical')['allowed'];
    }

    /** The whole-business view shows both books, so it needs access to both. */
    public static function canViewCombined(?User $user): bool
    {
        return self::hasBothLines() && self::canViewClinic($user) && self::canViewOptical($user);
    }

    /**
     * Switcher links for the statement pages, carrying the period across.
     *
     * @return array<string, array{label: string, url: string}>  empty unless the user can view more than one
     */
    public static function switcherLinks(?User $user, string $from, string $to): array
    {
        if (! self::hasBothLines()) return [];
        $links = [];
        if (self::canViewClinic($user)) $links[BusinessLine::CLINIC] = ['label' => 'Clinic', 'url' => route('admin.income-statement', ['fromDate' => $from, 'toDate' => $to])];
        if (self::canViewOptical($user)) $links[BusinessLine::OPTICAL] = ['label' => 'Optical', 'url' => route('optical.profit', ['from' => $from, 'to' => $to])];
        if (self::canViewCombined($user)) $links['combined'] = ['label' => 'Combined', 'url' => route('admin.combined-statement', ['from' => $from, 'to' => $to])];

        return count($links) > 1 ? $links : [];
    }
}
