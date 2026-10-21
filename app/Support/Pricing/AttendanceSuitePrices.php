<?php

namespace App\Support\Pricing;

use App\Services\PricingCatalogue;
use App\Support\Feature;

/**
 * The Attendance Suite price list shown on the public pricing page. Unlike the clinic plans, the
 * price follows the number of staff tracked: Basic (the core) by size, packages as Basic plus a
 * fixed amount at any size, and modules on their own at a flat price. Yearly is twelve months,
 * with no discount. From specialFrom() staff there is no list price; the page asks for a quote.
 *
 * The platform edits the figures under Platform → Pricing Page → Attendance prices; they are kept
 * in the pricing page settings ('attendance'). Until then the DEFAULT_* list below applies, as
 * agreed in the "Attendance Suite Price List" doc.
 */
class AttendanceSuitePrices
{
    /** Staff up to => Basic's monthly price. The last size runs up to the special-pricing number less one. */
    public const DEFAULT_SIZES = [50 => 150, 100 => 190, 150 => 225, 200 => 255, 250 => 285, 300 => 310,
        350 => 330, 400 => 350, 450 => 370, 500 => 390, 550 => 410, 599 => 430];

    public const DEFAULT_SPECIAL_FROM = 600;

    public const YEARLY_MONTHS = 12;

    /** Monthly price of each module bought on its own, at any size. */
    public const DEFAULT_MODULES = [
        Feature::ATTENDANCE_SHIFTS => 80, Feature::ATTENDANCE_OVERTIME => 40, Feature::ATTENDANCE_LEAVE => 40,
        Feature::ATTENDANCE_PAYROLL => 60, Feature::ATTENDANCE_PHONE => 40, Feature::ATTENDANCE_TRENDS => 30,
        Feature::ATTENDANCE_HOSPITAL => 120,
    ];

    /** Shorter names for the page than the platform's plan screen uses. */
    public const MODULE_LABELS = [
        Feature::ATTENDANCE_SHIFTS => 'Shifts & roster', Feature::ATTENDANCE_OVERTIME => 'Overtime approvals',
        Feature::ATTENDANCE_LEAVE => 'Leave types & balances', Feature::ATTENDANCE_PAYROLL => 'Payroll',
        Feature::ATTENDANCE_PHONE => 'Phone clock-in', Feature::ATTENDANCE_TRENDS => 'Trends',
        Feature::ATTENDANCE_HOSPITAL => 'Hospital tools',
    ];

    /** What Basic gives everyone, for the cards and the compare table. */
    public const CORE = [
        'Fingerprint & face terminal link (Hikvision)', 'Who is in today, live', 'Reports with CSV & PDF export',
        'Punch corrections with a reason', 'Holidays and leave requests', 'Staff see their own attendance',
        'Attendance in the owner\'s emails',
    ];

    /** key => name, the modules it adds over the package before it, and the defaults the platform can change. */
    public const PACKAGES = [
        'basic' => ['name' => 'Basic', 'adds' => [], 'uplift' => 0,
            'tagline' => 'Clock in at the terminal and see who came, who was late and who left early.'],
        'standard' => ['name' => 'Standard', 'adds' => [Feature::ATTENDANCE_SHIFTS, Feature::ATTENDANCE_OVERTIME, Feature::ATTENDANCE_LEAVE], 'uplift' => 120,
            'tagline' => 'Shifts, overtime and leave allowances for teams that work a roster.'],
        'professional' => ['name' => 'Professional', 'adds' => [Feature::ATTENDANCE_PAYROLL, Feature::ATTENDANCE_PHONE, Feature::ATTENDANCE_TRENDS], 'uplift' => 220,
            'tagline' => 'Pay worked out from attendance, phone clock-in and six-month trends.'],
        'complete' => ['name' => 'Complete', 'adds' => [Feature::ATTENDANCE_HOSPITAL], 'uplift' => 320,
            'tagline' => 'Departments, cover and rotations for hospitals and large facilities.'],
    ];

    public const DEFAULT_FEATURED = 'standard';

    /** What the platform saved, or [] for the default list. */
    public static function saved(): array
    {
        $saved = PricingCatalogue::settings()['attendance'] ?? null;

        return is_array($saved) ? $saved : [];
    }

    /** Staff up to => Basic's monthly price, smallest first. */
    public static function sizes(): array
    {
        $sizes = [];
        foreach (self::saved()['sizes'] ?? [] as $row) $sizes[(int) $row[0]] = (float) $row[1];
        if (! $sizes) $sizes = array_map('floatval', self::DEFAULT_SIZES);
        ksort($sizes);

        return $sizes;
    }

    public static function specialFrom(): int
    {
        return (int) (self::saved()['special_from'] ?? self::DEFAULT_SPECIAL_FROM);
    }

    /** module => monthly price. */
    public static function modules(): array
    {
        $saved = self::saved()['modules'] ?? [];

        return array_map(fn ($key) => (float) ($saved[$key] ?? self::DEFAULT_MODULES[$key]), array_combine(array_keys(self::DEFAULT_MODULES), array_keys(self::DEFAULT_MODULES)));
    }

    /** key => name, tagline, adds, uplift (on top of Basic), featured (shown as most popular). */
    public static function packages(): array
    {
        $saved = self::saved()['packages'] ?? [];
        $featured = array_key_exists('featured', self::saved()) ? self::saved()['featured'] : self::DEFAULT_FEATURED;
        $packages = [];
        foreach (self::PACKAGES as $key => $p) {
            $packages[$key] = ['name' => $p['name'], 'adds' => $p['adds'],
                'tagline' => (string) ($saved[$key]['tagline'] ?? $p['tagline']),
                'uplift' => $key === 'basic' ? 0.0 : (float) ($saved[$key]['uplift'] ?? $p['uplift']),
                'featured' => $featured === $key];
        }

        return $packages;
    }

    /** Every module in a package, its own and those of the packages before it. */
    public static function modulesIn(string $package): array
    {
        $modules = [];
        foreach (self::PACKAGES as $key => $p) {
            $modules = [...$modules, ...$p['adds']];
            if ($key === $package) return $modules;
        }

        return [];
    }

    /** The size a number of staff falls in (its "up to" figure), or null from specialFrom(). */
    public static function sizeFor(int $staff): ?int
    {
        if (max(1, $staff) >= self::specialFrom()) return null;
        foreach (array_keys(self::sizes()) as $size) if (max(1, $staff) <= $size) return $size;

        return null;
    }

    public static function label(string $module): string
    {
        return self::MODULE_LABELS[$module] ?? $module;
    }

    /**
     * The cheapest way to get the ticked modules for this many staff: Basic plus each module, or
     * the smallest package that has them all when that costs the same or less.
     * Returns special = true (and no prices) from specialFrom() staff.
     */
    public static function quote(int $staff, array $modules = [], ?string $package = null): array
    {
        $staff = max(1, $staff);
        $prices = self::modules();
        $modules = array_values(array_intersect(array_keys($prices), $modules));
        $packages = self::packages();
        if ($package !== null && ! isset($packages[$package])) $package = null;
        $size = self::sizeFor($staff);
        $base = ['staff' => $staff, 'size' => $size, 'modules' => $modules, 'special' => $size === null];
        if ($size === null) {
            $name = $package ? 'Attendance ' . $packages[$package]['name'] : 'Attendance Suite';

            return $base + ['package' => $package, 'name' => $name . ' · ' . self::specialFrom() . '+ staff', 'lines' => [], 'monthly' => 0.0, 'yearly' => 0.0, 'saving' => 0.0];
        }

        $basic = self::sizes()[$size];
        $price = fn (string $key) => $basic + $packages[$key]['uplift'];
        $build = fn (string $key, array $lines) => $base + ['package' => $key === 'custom' ? null : $key,
            'name' => ($key === 'custom' ? 'Attendance Basic' : 'Attendance ' . $packages[$key]['name']) . " · up to {$size} staff",
            'lines' => $lines, 'monthly' => $m = round(($key === 'custom' ? $basic : $price($key)) + array_sum(array_column($lines, 1)), 2),
            'yearly' => round($m * self::YEARLY_MONTHS, 2)];

        // A package picked on its card is quoted as it stands.
        if ($package !== null) return $build($package, []) + ['saving' => 0.0];

        $custom = $build('custom', array_map(fn ($m) => [self::label($m), $prices[$m]], $modules));
        foreach (array_keys($packages) as $key) {
            if ($key === 'basic' || array_diff($modules, self::modulesIn($key))) continue;
            if ($price($key) <= $custom['monthly']) return $build($key, []) + ['saving' => round($custom['monthly'] - $price($key), 2)];
            break;
        }

        return $custom + ['saving' => 0.0];
    }

    /** Everything the public page needs, in one array for its cards and its script. */
    public static function catalogue(): array
    {
        return [
            'sizes' => self::sizes(), 'special_from' => self::specialFrom(), 'yearly_months' => self::YEARLY_MONTHS, 'core' => self::CORE,
            'packages' => collect(self::packages())->map(fn ($p, $key) => $p + ['key' => $key, 'modules' => self::modulesIn($key)])->values()->all(),
            'modules' => collect(self::modules())->map(fn ($price, $key) => ['key' => $key, 'label' => self::label($key), 'price' => $price,
                'about' => \App\Support\PlanProduct::ATTENDANCE_PART_DESCRIPTIONS[$key] ?? ''])->values()->all(),
        ];
    }
}
