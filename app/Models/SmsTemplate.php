<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Support\Messaging\DefaultSmsTemplates;
use App\Support\Tenancy\TenantContext;

class SmsTemplate extends Model
{
    use BelongsToClinic;

    /** Placeholders every template may use; filled from the clinic and branch when the caller does not supply them. */
    public const CONTEXT_PLACEHOLDERS = ['[CLINIC]', '[BRANCH]', '[BRANCH_ADDRESS]', '[BRANCH_PHONE]', '[LINK]'];

    protected $fillable = ['key', 'label', 'message', 'placeholders'];

    protected $casts = ['placeholders' => 'array'];

    /** Render a template by key, replacing placeholders with given values. */
    public static function render(string $key, array $replacements, ?Branch $branch = null): string
    {
        $message = static::where('key', $key)->value('message') ?? DefaultSmsTemplates::message($key);
        if (!$message) return '';

        return static::fillMessage($message, $replacements, $branch);
    }

    /** Replace placeholders in any message text, adding clinic/branch values the caller left out. */
    public static function fillMessage(string $message, array $replacements, ?Branch $branch = null): string
    {
        $replacements += static::contextReplacements($branch);

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }

    /** Give the current clinic an editable row for every built-in template it does not have yet. */
    public static function ensureDefaults(): void
    {
        $existing = static::pluck('key')->all();

        foreach (DefaultSmsTemplates::TEMPLATES as $key => $template) {
            if (!in_array($key, $existing, true)) {
                static::create(['key' => $key] + $template);
            }
        }
    }

    public static function contextReplacements(?Branch $branch = null): array
    {
        $branch ??= app(TenantContext::class)->branch();
        $settings = Setting::getSettings();
        $clinic   = $settings->clinic_name ?: ($branch?->clinic?->name ?? 'the clinic');

        return [
            '[CLINIC]'         => $clinic,
            '[BRANCH]'         => $branch?->name ?? $clinic,
            '[BRANCH_ADDRESS]' => $branch?->address ?? '',
            '[BRANCH_PHONE]'   => $branch?->contact ?? '',
            '[LINK]'           => $settings->clinic_link ?? '',
        ];
    }
}
