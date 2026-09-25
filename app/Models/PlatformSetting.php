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

    private static function cached(): array
    {
        try {
            return Cache::rememberForever('platform_settings', fn () => static::query()->pluck('value', 'key')->all());
        } catch (\Throwable) {
            return []; // table missing before migration
        }
    }
}
