<?php

namespace App\Support;

use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Report figures for periods that are over, kept so reopening a report doesn't add up the same
 * past months again. A period that includes today is always worked out live.
 *
 * Every save or delete of a clinic's row bumps a version for each past month that row is dated in
 * (any *_at / *_date / *_on field, before or after the change), so a refund, correction, void or
 * back-dated expense refreshes the months it touches at once. Writes about today, the usual case,
 * bump nothing. The TTL bounds anything written without model events (bulk updates).
 */
final class FinanceCache
{
    public const TTL = 1800;

    /** Tables whose rows never move report figures; skipped to keep saves cheap. */
    private const IGNORE = [
        'audit_trails', 'login_logs', 'sms_logs', 'app_notifications', 'sessions', 'owner_emails',
        'owner_alert_items', 'user_guides', 'staff_messages', 'clinic_usage_daily', 'clinic_usage_users',
        'attendance_punches', 'attendance_captures', 'attendance_roster', 'cache', 'jobs',
    ];

    private static ?bool $enabled = null;

    /** The figures for a period: kept when the period is over, live when it includes today. */
    public static function remember(string $kind, CarbonInterface $from, CarbonInterface $to, Closure $compute, array $extra = []): mixed
    {
        $context = app(TenantContext::class);
        if (! self::enabled() || ! $context->resolved() || Carbon::parse($to)->startOfDay()->gte(today())) {
            return $compute();
        }
        $clinic = $context->clinicId();
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        $versionKeys = [];
        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $versionKeys[] = self::versionKey($clinic, $month->format('Y-m'));
        }
        $versions = array_map(fn ($v) => (int) $v, array_values(Cache::many($versionKeys)));

        $key = 'fin:'.$clinic.':'.md5(json_encode([
            $kind, $from->toDateString(), $to->toDateString(), $context->branchId(), $context->authorizedBranchIds(), $extra, $versions,
        ]));

        return Cache::remember($key, self::TTL, $compute);
    }

    /** A clinic row was saved or deleted: refresh the past months it is dated in. */
    public static function touched(Model $model): void
    {
        if (! self::enabled() || in_array($model->getTable(), self::IGNORE, true)) return;
        $clinic = $model->getAttribute('clinic_id') ?? $model->getOriginal('clinic_id');
        if (! $clinic) return;

        $today = today()->toDateString();
        $months = [];
        foreach ([$model->getAttributes(), $model->getOriginal()] as $values) {
            foreach ($values as $column => $value) {
                if (! is_string($column) || ! preg_match('/(_at|_date|_on|Date)$/', $column) || $value === null) continue;
                $date = $value instanceof CarbonInterface ? $value->toDateString() : substr((string) $value, 0, 10);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date >= $today) continue;
                $months[substr($date, 0, 7)] = true;
            }
        }
        foreach (array_keys($months) as $month) {
            self::bump((int) $clinic, $month);
        }
    }

    /** Forget every kept figure for a clinic (imports and other bulk changes). */
    public static function flushClinic(int $clinicId): void
    {
        Cache::forever(self::generationKey($clinicId), (int) Cache::get(self::generationKey($clinicId), 0) + 1);
    }

    private static function bump(int $clinic, string $month): void
    {
        $key = self::versionKey($clinic, $month);
        Cache::forever($key, (int) Cache::get($key, 0) + 1);
    }

    private static function versionKey(int $clinic, string $month): string
    {
        // The clinic-wide generation is part of every month key, so flushClinic renews them all.
        return 'fin-v:'.$clinic.':'.(int) Cache::get(self::generationKey($clinic), 0).':'.$month;
    }

    private static function generationKey(int $clinic): string
    {
        return 'fin-gen:'.$clinic;
    }

    private static function enabled(): bool
    {
        // Off in tests unless a test switches it on: many tests write rows without model events.
        return self::$enabled ??= (bool) config('finance.report_cache', ! app()->environment('testing'));
    }

    /** Tests: switch the cache on or off. */
    public static function enable(bool $on): void
    {
        self::$enabled = $on;
    }
}
