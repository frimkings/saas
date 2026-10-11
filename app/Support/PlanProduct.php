<?php

namespace App\Support;

/**
 * What a subscription plan covers: the clinic, the optical shop, both, or staff attendance
 * on its own, plus the extra features on top. Stored as feature keys on the plan and its
 * subscriptions:
 *
 *   ['clinical', 'optical', 'sms_campaigns', ...]  product keys + chosen extras
 *   ['optical', '*']                               optical shop with every extra
 *   ['attendance_only', 'attendance', ...]         attendance and nothing else
 *   [] or ['*'] (no product key)                   legacy "everything" plans
 *
 * '*' only ever means "every extra feature"; it never adds a product the plan doesn't name.
 * Attendance is an extra for clinics and optical shops, and the whole of the attendance-only
 * product, which can only take the extras in ATTENDANCE_EXTRAS.
 */
class PlanProduct
{
    public const CLINIC = 'clinic';
    public const OPTICAL = 'optical';
    public const BOTH = 'both';
    public const ATTENDANCE = 'attendance';

    public const PRODUCTS = [
        self::CLINIC => 'Clinic',
        self::OPTICAL => 'Optical shop',
        self::BOTH => 'Clinic + Optical',
        self::ATTENDANCE => 'Attendance only',
    ];

    /** What each product is, for the screens where one is picked. */
    public const DESCRIPTIONS = [
        self::CLINIC => 'Patients, consultations, cashier, clinic inventory',
        self::OPTICAL => 'Optical orders, lab, POS, optical stock, partner clinics',
        self::BOTH => 'Everything in both modules',
        self::ATTENDANCE => 'Staff attendance from a terminal, leave and reports; no clinic or shop',
    ];

    /** Feature keys that decide the product. */
    public const PRODUCT_KEYS = [Feature::CLINICAL, Feature::OPTICAL, Feature::ATTENDANCE_ONLY];

    /** The only extras that mean anything to an attendance-only subscriber. */
    public const ATTENDANCE_EXTRAS = [
        Feature::AUDIT_TRAIL, Feature::DAILY_SUMMARY, Feature::WEEKLY_SUMMARY, Feature::MONTHLY_SUMMARY,
        Feature::MANUAL_BACKUP, Feature::SCHEDULED_BACKUPS, Feature::UNLIMITED_USERS,
    ];

    /** Optional extras, with the product they belong to (null = either). */
    public const EXTRAS = [
        Feature::ATTENDANCE => ['Staff attendance (terminal, leave, reports)', null],
        Feature::SMS_CAMPAIGNS => ['SMS reminders & campaigns', null],
        Feature::APPROVALS => ['Approval workflows', null],
        Feature::AUDIT_TRAIL => ['Audit trail & login history', null],
        Feature::EXPENSE_TRACKING => ['Expense tracking', null],
        Feature::ADVANCED_REPORTS => ['Advanced reports (income statement)', null],
        Feature::DAILY_SUMMARY => ['Daily sales email to owner', null],
        Feature::WEEKLY_SUMMARY => ['Weekly sales email to owner', null],
        Feature::MONTHLY_SUMMARY => ['Monthly sales email to owner', null],
        Feature::MORNING_ALERTS => ['Morning alerts email (low stock, expiry, bills, lab)', null],
        Feature::MANUAL_BACKUP => ['Manual backups', null],
        Feature::SCHEDULED_BACKUPS => ['Scheduled backups', null],
        Feature::INVENTORY => ['Clinic inventory', self::CLINIC],
        Feature::APPOINTMENTS => ['Appointments', self::CLINIC],
        Feature::REFERRALS => ['Referral letters', self::CLINIC],
        Feature::OUTSTANDING_BALANCES => ['Outstanding balances', self::CLINIC],
        Feature::SPECTACLES_PRO => ['Spectacle orders pro', self::CLINIC],
        Feature::UNLIMITED_USERS => ['Unlimited users', null],
    ];

    /** Product keys for a product. */
    public static function keys(string $product): array
    {
        return match ($product) {
            self::CLINIC => [Feature::CLINICAL],
            self::OPTICAL => [Feature::OPTICAL],
            self::ATTENDANCE => [Feature::ATTENDANCE_ONLY, Feature::ATTENDANCE],
            default => [Feature::CLINICAL, Feature::OPTICAL],
        };
    }

    /** The extras a product can be given: [key => label]. Attendance itself is the whole of the attendance-only product. */
    public static function extrasFor(string $product): array
    {
        return collect(self::EXTRAS)
            ->filter(fn ($extra, $key) => match ($product) {
                self::ATTENDANCE => in_array($key, self::ATTENDANCE_EXTRAS, true),
                self::OPTICAL => $extra[1] !== self::CLINIC,
                default => true,
            })
            ->map(fn ($extra) => $extra[0])->all();
    }

    /** The feature list to store for a product and its extras. */
    public static function features(string $product, array $extras, bool $allExtras = false): array
    {
        // Only the attendance product narrows the list here; the screens leave clinic-only extras off an optical plan.
        $extras = array_values(array_intersect(array_keys($product === self::ATTENDANCE ? self::extrasFor($product) : self::EXTRAS), $extras));
        return array_values(array_unique(array_merge(self::keys($product), $allExtras ? ['*'] : $extras)));
    }

    /** Whether the list names its products (new plans) or is a legacy "everything" list. */
    public static function isProductAware(?array $features): bool
    {
        return is_array($features) && array_intersect(self::PRODUCT_KEYS, $features) !== [];
    }

    /** Which product a feature list covers. Legacy lists cover both. */
    public static function productOf(?array $features): string
    {
        if (! self::isProductAware($features)) return self::BOTH;
        $clinic = in_array(Feature::CLINICAL, $features, true);
        $optical = in_array(Feature::OPTICAL, $features, true);
        if (! $clinic && ! $optical) return self::ATTENDANCE;
        return $clinic && $optical ? self::BOTH : ($optical ? self::OPTICAL : self::CLINIC);
    }

    public static function label(?string $product): string
    {
        return self::PRODUCTS[$product ?? self::BOTH] ?? self::PRODUCTS[self::BOTH];
    }

    /** Does a feature list include this feature? */
    public static function allows(?array $features, string $feature): bool
    {
        if (empty($features)) return true;
        if (! self::isProductAware($features)) return in_array('*', $features, true) || in_array($feature, $features, true);
        if (in_array($feature, self::PRODUCT_KEYS, true)) return in_array($feature, $features, true);
        if (self::productOf($features) === self::ATTENDANCE) {
            // Attendance and its own short list of extras; nothing from the clinic or the shop.
            if ($feature === Feature::ATTENDANCE) return true;
            if (! in_array($feature, self::ATTENDANCE_EXTRAS, true)) return false;
        }
        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

    /** The extras a list includes ('*' = all of them). */
    public static function extrasOf(?array $features): array
    {
        $possible = array_keys(self::isProductAware($features) ? self::extrasFor(self::productOf($features)) : self::EXTRAS);
        if (empty($features) || in_array('*', $features, true)) return $possible;
        return array_values(array_intersect($possible, $features));
    }
}
