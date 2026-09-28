<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Platform-wide key/value settings (not scoped to a clinic). */
class PlatformSetting extends Model
{
    public const SUPPORT_KEYS = ['support_name', 'support_phone', 'support_whatsapp', 'support_email'];

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::cached()[$key] ?? $default;
    }

    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => filled($value) ? trim((string) $value) : null]);
        }
        Cache::forget('platform_settings');
    }

    /** Support contact shown to locked clinics, falling back to the PLATFORM_SUPPORT_* environment values. */
    public static function support(): array
    {
        return [
            'name'     => static::get('support_name') ?? config('app.support.name') ?? config('app.name'),
            'phone'    => static::get('support_phone') ?? config('app.support.phone'),
            'whatsapp' => static::get('support_whatsapp') ?? config('app.support.whatsapp'),
            'email'    => static::get('support_email') ?? config('app.support.email'),
        ];
    }

    /**
     * Branding for guest pages (login, password reset). In multi-clinic mode these pages are
     * shared by every clinic, so they show the developer/platform details instead of any one
     * clinic's name and logo. Single-clinic installs keep that clinic's branding.
     */
    public static function guestBranding(): array
    {
        if (config('tenancy.enabled')) {
            $support = static::support();

            return ['name' => $support['name'], 'logo' => null, 'phone' => $support['phone'],
                'email' => $support['email'], 'platform' => true];
        }

        $settings = Setting::getSettings();

        return ['name' => $settings->clinic_name ?? config('app.name', 'Eye Clinic'), 'logo' => $settings->logoDataUri(),
            'phone' => null, 'email' => null, 'platform' => false];
    }

    private static function cached(): array
    {
        try {
            return Cache::rememberForever('platform_settings', fn () => static::query()->pluck('value', 'key')->all());
        } catch (\Throwable) {
            return []; // table missing before migration
        }
    }
}
