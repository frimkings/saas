<?php

namespace App\Support\Access;

use App\Models\OpticalSetting;
use App\Models\Setting;
use App\Models\User;

/**
 * The largest discount (as a percent of what can be discounted) each role may give at each till
 * without asking for approval. The Owner sets them; the Owner has no limit. A person with several
 * roles gets the highest of them. Above the limit the clinic till sends an approval request and
 * the optical till refuses until a Manager or the Owner rings the sale up.
 *
 * Defaults keep the behaviour from before limits existed: Managers give any discount, desk staff
 * need approval for every discount at the clinic till and may give up to the shop's old setting
 * (10% unless changed) at the optical till.
 */
final class DiscountLimits
{
    public const CLINICAL = 'clinical';
    public const OPTICAL = 'optical';
    public const LINES = [self::CLINICAL => 'Clinic till', self::OPTICAL => 'Optical till'];

    /** Roles the Owner sets limits for, in the order the settings show them. Any other role gets the till's default. */
    public const ROLES = ['Manager', 'Secretary', 'Cashier', 'Optical Assistant'];

    public static function defaultFor(string $role, string $line): int
    {
        if ($role === 'Manager') return 100;

        return $line === self::OPTICAL ? OpticalSetting::posMaxDiscountPercent() : 0;
    }

    /** @return array<string, array<string, int>> role => line => percent, defaults filled in */
    public static function all(): array
    {
        $stored = (array) (Setting::getSettings()?->discount_limits ?? []);
        $limits = [];
        foreach (self::ROLES as $role) {
            foreach (array_keys(self::LINES) as $line) {
                $value = $stored[$role][$line] ?? null;
                $limits[$role][$line] = is_numeric($value) ? self::clamp((int) $value) : self::defaultFor($role, $line);
            }
        }

        return $limits;
    }

    public static function forRole(string $role, string $line): int
    {
        if ($role === RoleMap::OWNER) return 100;

        return self::all()[$role][$line] ?? self::defaultFor($role, $line);
    }

    /** The highest limit among this person's roles; the Owner has none (100%). */
    public static function forUser(?User $user, string $line): int
    {
        if (! $user) return 0;
        $limits = self::all();
        $best = 0;
        foreach ($user->getRoleNames() as $role) {
            if ($role === RoleMap::OWNER) return 100;
            $best = max($best, $limits[$role][$line] ?? self::defaultFor($role, $line));
        }

        return $best;
    }

    /** True when the discount is within the person's limit for that till. */
    public static function allows(?User $user, string $line, float $discount, float $base): bool
    {
        if ($discount <= 0) return true;
        if ($base <= 0) return false;

        return round($discount, 2) <= round($base * self::forUser($user, $line) / 100, 2);
    }

    /** @param array<string, array<string, mixed>> $limits */
    public static function save(array $limits): void
    {
        $clean = [];
        foreach (self::ROLES as $role) {
            foreach (array_keys(self::LINES) as $line) {
                $clean[$role][$line] = self::clamp((int) ($limits[$role][$line] ?? self::defaultFor($role, $line)));
            }
        }
        Setting::getSettings()->update(['discount_limits' => $clean]);
        Setting::forgetCached();
    }

    private static function clamp(int $percent): int
    {
        return min(100, max(0, $percent));
    }
}
