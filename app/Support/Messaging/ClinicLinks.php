<?php

namespace App\Support\Messaging;

use App\Models\Branch;
use App\Models\Setting;

/**
 * The clinic's links, as SMS placeholders. Set once under Clinic Links; every template can use
 * them. A branch's own map or WhatsApp link wins over the clinic's.
 */
final class ClinicLinks
{
    /** Placeholder => [settings column, label, branch column or null]. In display order. */
    public const LINKS = [
        '[MAP_LINK]'      => ['clinic_link', 'Location (Google Maps)', 'map_link'],
        '[WHATSAPP_LINK]' => ['whatsapp_link', 'WhatsApp', 'whatsapp_link'],
        '[REVIEW_LINK]'   => ['review_link', 'Google review', null],
        '[WEBSITE]'       => ['website_link', 'Website', null],
        '[BOOKING_LINK]'  => ['booking_link', 'Online booking', null],
        '[FACEBOOK]'      => ['facebook_link', 'Facebook', null],
        '[INSTAGRAM]'     => ['instagram_link', 'Instagram', null],
        '[TIKTOK]'        => ['tiktok_link', 'TikTok', null],
    ];

    /** The old location placeholder, kept so existing templates keep working. */
    public const LEGACY = '[LINK]';

    /** @return array<string, string> placeholder => link ('' when not set) */
    public static function replacements(?Branch $branch = null): array
    {
        $settings = Setting::getSettings();
        $values = [];
        foreach (self::LINKS as $placeholder => [$column, , $branchColumn]) {
            $values[$placeholder] = trim((string) (($branchColumn ? $branch?->{$branchColumn} : null) ?: $settings->{$column}));
        }
        $values[self::LEGACY] = $values['[MAP_LINK]'];

        return $values;
    }

    /** Link placeholders a message uses that have no link set (so the message would go out with a gap). */
    public static function missingIn(string $message, ?Branch $branch = null): array
    {
        $values = self::replacements($branch);

        return array_values(array_filter(array_keys($values), fn ($placeholder) => $values[$placeholder] === '' && str_contains($message, $placeholder)));
    }

    /**
     * A WhatsApp number becomes a wa.me link (0241234567 → https://wa.me/233241234567); a link is
     * kept as it is.
     */
    public static function whatsapp(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[+\d\s()-]+$/', $value)) {
            return 'https://wa.me/' . PhoneNumber::normalize($value);
        }

        return $value;
    }
}
