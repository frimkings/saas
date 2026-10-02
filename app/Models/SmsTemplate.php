<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Support\Messaging\DefaultSmsTemplates;
use App\Support\Tenancy\TenantContext;

class SmsTemplate extends Model
{
    use BelongsToClinic;

    /**
     * Placeholders every template may use; filled from the clinic and branch when the caller does
     * not supply them. The links come from Settings → Clinic Links (App\Support\Messaging\ClinicLinks);
     * the old [LINK] still works as the location link.
     */
    public const CONTEXT_PLACEHOLDERS = ['[CLINIC]', '[BRANCH]', '[BRANCH_ADDRESS]', '[BRANCH_PHONE]',
        '[MAP_LINK]', '[WHATSAPP_LINK]', '[REVIEW_LINK]', '[WEBSITE]', '[BOOKING_LINK]', '[FACEBOOK]', '[INSTAGRAM]', '[TIKTOK]'];

    protected $fillable = ['key', 'label', 'message', 'placeholders', 'is_enabled'];

    protected $casts = ['placeholders' => 'array', 'is_enabled' => 'boolean'];

    /**
     * Render a template by key, replacing placeholders with given values. Returns '' when the
     * clinic has switched this message off, so nothing is sent; $evenIfOff is for text staff
     * send themselves (a WhatsApp link), not for automatic SMS.
     */
    public static function render(string $key, array $replacements, ?Branch $branch = null, bool $evenIfOff = false): string
    {
        $row = static::where('key', $key)->first(['message', 'is_enabled']);
        if (! $evenIfOff && ! ($row ? $row->is_enabled : DefaultSmsTemplates::onByDefault($key))) return '';
        $message = $row?->message ?? DefaultSmsTemplates::message($key);
        if (!$message) return '';
        // A message that uses a clinic link nobody has set is not sent rather than going out with a gap.
        $unset = array_diff(\App\Support\Messaging\ClinicLinks::missingIn($message, $branch ?? app(TenantContext::class)->branch()), array_keys(array_filter($replacements)));
        if ($unset) return '';

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
                static::create(['key' => $key, 'is_enabled' => DefaultSmsTemplates::onByDefault($key)] + $template);
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
        ] + \App\Support\Messaging\ClinicLinks::replacements($branch);
    }
}
