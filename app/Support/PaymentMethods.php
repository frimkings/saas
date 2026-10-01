<?php

namespace App\Support;

use App\Models\PaymentMethod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The payment methods each till offers: one list for the clinical tills (clearance, POS,
 * outstanding balances) and one for optical. A clinic can switch methods off, rename them
 * and add its own; until it does, it uses the built-in list. Payments store the method key,
 * so a renamed or switched-off method still shows its (current) name on old payments.
 */
final class PaymentMethods
{
    public const CLINIC = 'clinic';
    public const OPTICAL = 'optical';

    /** Built-in methods: key => default name. */
    public const BUILT_IN = [
        'cash'          => 'Cash',
        'momo'          => 'Mobile Money',
        'card'          => 'Card',
        'cheque'        => 'Cheque',
        'bank_transfer' => 'Bank transfer',
        'code'          => 'Hubtel Wallet',
    ];

    /** What each line offers before the clinic changes anything. */
    public const DEFAULTS = [
        self::CLINIC  => ['cash', 'momo', 'card', 'cheque', 'code'],
        self::OPTICAL => ['cash', 'momo', 'card', 'bank_transfer'],
    ];

    /**
     * Every method for a line, switched on or off, in order.
     *
     * @return Collection<int, array{key: string, label: string, is_active: bool, built_in: bool}>
     */
    public static function all(string $line): Collection
    {
        $line = self::line($line);
        // Cached for this request only (the container is rebuilt per request), per clinic.
        $cacheKey = 'payment-methods.' . (PaymentMethod::clinicIdForWrite() ?? 0) . '.' . $line;
        if (app()->bound($cacheKey)) {
            return app($cacheKey);
        }

        $methods = (function () use ($line) {
            $rows = PaymentMethod::where('business_line', $line)->orderBy('sort_order')->orderBy('id')->get();

            if ($rows->isEmpty()) {
                return collect(self::DEFAULTS[$line])->map(fn (string $key) => [
                    'key' => $key, 'label' => self::BUILT_IN[$key], 'is_active' => true, 'built_in' => true,
                ])->values();
            }

            return $rows->map(fn (PaymentMethod $row) => [
                'key' => $row->key, 'label' => $row->label, 'is_active' => $row->is_active,
                'built_in' => array_key_exists($row->key, self::BUILT_IN),
            ])->values();
        })();
        app()->instance($cacheKey, $methods);

        return $methods;
    }

    /** The methods a till offers: key => name. */
    public static function active(string $line): array
    {
        return self::all($line)->where('is_active', true)->pluck('label', 'key')->all();
    }

    /** The method a till starts on: the first one switched on. */
    public static function first(string $line): string
    {
        return array_key_first(self::active($line)) ?? 'cash';
    }

    /** A steady chart colour per method. */
    public static function color(?string $key): string
    {
        $fixed = ['cash' => '#28a745', 'card' => '#007bff', 'momo' => '#fd7e14', 'code' => '#6f42c1', 'cheque' => '#17a2b8', 'bank_transfer' => '#20c997'];
        $palette = ['#e83e8c', '#6610f2', '#ffc107', '#795548', '#3f51b5', '#009688', '#ff5722'];

        return $fixed[$key] ?? $palette[abs(crc32((string) $key)) % count($palette)];
    }

    public static function keys(string $line): array
    {
        return array_keys(self::active($line));
    }

    public static function isActive(string $line, ?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::active($line));
    }

    /** The name to show for a stored method key, from either line, switched on or not. */
    public static function label(?string $key, ?string $line = null): string
    {
        if ($key === null || $key === '') {
            return 'Other';
        }

        foreach ($line ? [self::line($line)] : [self::CLINIC, self::OPTICAL] as $candidate) {
            $found = self::all($candidate)->firstWhere('key', $key);
            if ($found) {
                return $found['label'];
            }
        }

        return self::BUILT_IN[$key] ?? Str::headline($key);
    }

    /** A key for a new clinic-defined method: "custom_" plus its name, made unique for the line. */
    public static function newKey(string $line, string $label): string
    {
        $base = 'custom_' . (Str::slug($label, '_') ?: 'method');
        $base = substr($base, 0, 44);
        $existing = self::all($line)->pluck('key')->all();
        $key = $base;
        for ($n = 2; in_array($key, $existing, true) || array_key_exists($key, self::BUILT_IN); $n++) {
            $key = $base . '_' . $n;
        }

        return $key;
    }

    /** Forget this request's cache after the clinic changes its methods. */
    public static function flush(): void
    {
        foreach ([self::CLINIC, self::OPTICAL] as $line) {
            app()->forgetInstance('payment-methods.' . (PaymentMethod::clinicIdForWrite() ?? 0) . '.' . $line);
        }
    }

    private static function line(string $line): string
    {
        return $line === self::OPTICAL ? self::OPTICAL : self::CLINIC;
    }
}
