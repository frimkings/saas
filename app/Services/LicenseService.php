<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LicenseService
{
    private static array $cache = [];
    private static array $cacheLoaded = [];

    public static function tier(): string
    {
        if (static::load() !== null) {
            return 'pro';
        }
        return static::isInTrial() ? 'trial' : 'free';
    }

    public static function has(string $feature): bool
    {
        if (app()->environment('testing') && !app(\App\Support\Tenancy\TenantContext::class)->resolved()) {
            return true;
        }
        return app(ClinicAccessService::class)->access($feature)['allowed'];
    }

    public static function offlineAccess(?string $feature = null): array
    {
        $setting = Setting::getSettings();
        static::installationId();
        $result = empty($setting->license_key) ? null : static::parseAndVerify($setting->license_key, allowExpired: true);
        $payload = ($result['ok'] ?? false) ? $result['payload'] : null;
        $expires = $payload['expires'] ?? ($setting->fresh()?->trial_started_at?->copy()->addDays(30)->toDateString());
        $rollback = $setting->license_last_seen && now()->toDateString() < (string) $setting->license_last_seen;
        $invalid = !empty($setting->license_key) && !$payload;
        // The license stays fully usable for `subscriptions.grace_days` after its expiry date.
        $graceEnds = $expires ? Carbon::parse($expires)->addDays((int) config('subscriptions.grace_days', 1))->toDateString() : null;
        $readOnly = $invalid || $rollback || !$graceEnds || now()->toDateString() > $graceEnds;
        $status = $readOnly ? 'restricted' : ($payload ? 'active' : 'trial');
        $features = $payload['features'] ?? ['*'];
        // Keys name their product (clinical and/or optical) like hosted plans; older keys and
        // trials name none and include both. Other free features are always available.
        $allowed = !$feature || (in_array($feature, \App\Support\PlanProduct::PRODUCT_KEYS, true)
            ? (!\App\Support\PlanProduct::isProductAware($features) || in_array($feature, $features, true))
            : (in_array('*', $features, true) || in_array($feature, $features, true)
                || !in_array($feature, config('license.pro_features', []), true)));
        if (!$rollback && $setting->license_last_seen !== now()->toDateString()) {
            $setting->license_last_seen = now()->toDateString();
            $setting->saveQuietly();
        }
        return [
            'allowed' => $allowed, 'read_only' => $readOnly, 'status' => $status,
            'reason' => $allowed ? null : 'This feature is not included in your license.',
            'expires' => $expires, 'grace_ends' => $graceEnds, 'invalid' => $invalid || $rollback,
            'plan' => $payload['plan'] ?? ($payload ? 'Pro' : 'Trial'), 'features' => $features,
        ];
    }

    public static function isInTrial(): bool
    {
        try {
            $setting = Setting::first();
            if (!$setting) {
                return false;
            }
            if (empty($setting->trial_started_at)) {
                return false;
            }
            $trialEnds = Carbon::parse($setting->trial_started_at)->addDays(30);
            return now()->startOfDay()->lte($trialEnds->startOfDay());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isExpired(): bool
    {
        try {
            $setting = Setting::first();
            if (!$setting || empty($setting->license_key)) {
                return false;
            }
            $result = static::parseAndVerify($setting->license_key, skipInstallationCheck: true);
            if (!$result['ok']) {
                return false;
            }
            return now()->toDateString() > $result['payload']['expires'];
        } catch (\Throwable) {
            return false;
        }
    }

    public static function installationId(): string
    {
        try {
            $setting = Setting::first();
            if (!$setting) {
                return '';
            }
            if (empty($setting->installation_id)) {
                $setting->installation_id = (string) Str::uuid();
                $setting->trial_started_at = now()->toDateString();
                $setting->save();
            }
            return $setting->installation_id;
        } catch (\Throwable) {
            return '';
        }
    }

    public static function info(): array
    {
        $payload  = static::load();
        $installId = static::installationId();

        if ($payload === null) {
            $expiredInfo = static::expiredKeyInfo();

            if (static::isInTrial()) {
                try {
                    $setting = Setting::first();
                    $trialEnds = Carbon::parse($setting->trial_started_at)->addDays(30);
                    $trialDaysLeft = (int) now()->startOfDay()->diffInDays($trialEnds->startOfDay(), false);
                    return array_merge([
                        'tier'             => 'trial',
                        'trial_expires'    => $trialEnds->toDateString(),
                        'trial_days_left'  => $trialDaysLeft,
                        'installation_id'  => $installId,
                    ], $expiredInfo);
                } catch (\Throwable) {}
            }

            return array_merge([
                'tier'            => 'free',
                'installation_id' => $installId,
            ], $expiredInfo);
        }

        $expiresDate = Carbon::parse($payload['expires']);
        $daysLeft    = (int) now()->startOfDay()->diffInDays($expiresDate->startOfDay(), false);

        $clinicName = Setting::first()?->clinic_name ?? $payload['clinic'] ?? '';

        return [
            'tier'            => 'pro',
            'clinic'          => $clinicName,
            'issued'          => $payload['issued'] ?? '',
            'expires'         => $payload['expires'],
            'days_left'       => $daysLeft,
            'installation_id' => $installId,
        ];
    }

    public static function activate(string $key): array
    {
        if (app(ClinicAccessService::class)->hosted()) {
            return ['ok' => false, 'message' => 'This clinic uses a developer-managed subscription.'];
        }
        $result = static::parseAndVerify(trim($key));
        if (!$result['ok']) {
            return $result;
        }

        try {
            $setting = Setting::first();
            if (!$setting) {
                return ['ok' => false, 'message' => 'Settings record not found.'];
            }
            $setting->license_key       = trim($key);
            $setting->license_last_seen = now()->toDateString();
            $setting->save();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save license: ' . $e->getMessage()];
        }

        $scope = static::scopeKey();
        static::$cache[$scope] = $result['payload'];
        static::$cacheLoaded[$scope] = true;

        $clinic  = $setting->clinic_name ?? $result['payload']['clinic'] ?? 'your clinic';
        $expires = $result['payload']['expires'];

        return ['ok' => true, 'message' => "License activated for {$clinic}. Valid until {$expires}."];
    }

    public static function load(): ?array
    {
        $scope = static::scopeKey();
        if (static::$cacheLoaded[$scope] ?? false) {
            return static::$cache[$scope] ?? null;
        }

        static::$cacheLoaded[$scope] = true;
        static::$cache[$scope] = null;

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                return null;
            }

            $setting = Setting::first();
            if (!$setting || empty($setting->license_key)) {
                return null;
            }

            $result = static::parseAndVerify($setting->license_key);
            if (!$result['ok']) {
                return null;
            }

            $payload = $result['payload'];
            $today   = now()->toDateString();
            $lastSeen = $setting->license_last_seen
                ? (string) $setting->license_last_seen
                : null;

            // Clock-rollback detection: reject any backward movement (zero tolerance)
            if ($lastSeen && $today < $lastSeen) {
                return null;
            }

            // Advance last-seen date
            if ($today !== $lastSeen) {
                $setting->license_last_seen = $today;
                $setting->saveQuietly();
            }

            static::$cache[$scope] = $payload;
        } catch (\Throwable) {
            // Fail safely — free tier
        }

        return static::$cache[$scope] ?? null;
    }

    // Reset cache (call after activation or in tests)
    public static function clearCache(): void
    {
        static::$cache = [];
        static::$cacheLoaded = [];
    }

    private static function scopeKey(): string
    {
        return (string) (app(\App\Support\Tenancy\TenantContext::class)->clinicId() ?? 'unresolved');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function parseAndVerify(string $key, bool $skipInstallationCheck = false, bool $allowExpired = false): array
    {
        if (!str_starts_with($key, 'EYECLINIC-PRO-')) {
            return ['ok' => false, 'message' => 'Invalid license key format.'];
        }

        $inner = substr($key, strlen('EYECLINIC-PRO-'));
        $dotPos = strrpos($inner, '.');

        if ($dotPos === false) {
            return ['ok' => false, 'message' => 'Invalid license key format.'];
        }

        $payloadB64 = substr($inner, 0, $dotPos);
        $sigB64     = substr($inner, $dotPos + 1);

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return ['ok' => false, 'message' => 'Sodium extension is not enabled. Contact your system administrator.'];
        }

        $publicKeyB64 = config('license.public_key', '');
        if (empty($publicKeyB64)) {
            return ['ok' => false, 'message' => 'License system is not configured on this installation.'];
        }

        $publicKey = base64_decode($publicKeyB64, true);
        $signature = static::base64urlDecode($sigB64);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ['ok' => false, 'message' => 'Invalid license configuration (bad public key).'];
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !@sodium_crypto_sign_verify_detached($signature, $payloadB64, $publicKey)) {
            return ['ok' => false, 'message' => 'License key signature is invalid. The key may have been tampered with.'];
        }

        $json    = static::base64urlDecode($payloadB64);
        $payload = json_decode($json, true);

        if (!is_array($payload) || !isset($payload['tier'], $payload['installation_id'], $payload['expires'])) {
            return ['ok' => false, 'message' => 'License key payload is malformed.'];
        }

        if ($payload['tier'] !== 'pro' || !is_string($payload['expires'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['expires'])
            || !checkdate((int) substr($payload['expires'], 5, 2), (int) substr($payload['expires'], 8, 2), (int) substr($payload['expires'], 0, 4))
            || (isset($payload['features']) && (!is_array($payload['features']) || count(array_filter($payload['features'], 'is_string')) !== count($payload['features'])))) {
            return ['ok' => false, 'message' => 'License key payload is malformed.'];
        }

        if (!$skipInstallationCheck && $payload['installation_id'] !== static::installationId()) {
            return ['ok' => false, 'message' => 'This key was issued for a different installation. Contact your supplier.'];
        }

        if (!$allowExpired && now()->toDateString() > $payload['expires']) {
            return ['ok' => false, 'message' => 'License key expired on ' . $payload['expires'] . '. Please renew your license.'];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    private static function expiredKeyInfo(): array
    {
        try {
            $setting = Setting::first();
            if (!$setting || empty($setting->license_key)) {
                return [];
            }
            // Only read expiry from a fully verified (signed) key
            $result = static::parseAndVerify($setting->license_key, skipInstallationCheck: true);
            if (isset($result['payload']['expires']) && now()->toDateString() > $result['payload']['expires']) {
                return ['expired_on' => $result['payload']['expires']];
            }
        } catch (\Throwable) {}
        return [];
    }

    private static function base64urlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $mod    = strlen($padded) % 4;
        if ($mod) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        return base64_decode($padded, true) ?: '';
    }
}
