<?php

namespace App\Support\Messaging;

use App\Models\Setting;

/**
 * Channel choice for messages the server sends on its own (reminders, birthdays, recall,
 * renewals). WhatsApp needs the clinic's optional Meta Cloud API setup plus an approved
 * template; without it the message goes by SMS instead of silently not going at all.
 */
class AutomaticChannels
{
    /** @return array{sms: bool, whatsapp: bool} */
    public static function pick(?string $preferred, Setting $settings, ?string $whatsAppTemplate): array
    {
        $preferred   = $preferred ?: 'sms';
        $waAvailable = (bool) $settings->whatsapp_enabled
            && filled($settings->whatsapp_phone_number_id)
            && filled($settings->whatsapp_access_token)
            && filled($whatsAppTemplate);

        $whatsapp = $waAvailable && in_array($preferred, ['whatsapp', 'both'], true);
        $sms      = in_array($preferred, ['sms', 'both'], true) || ($preferred === 'whatsapp' && !$whatsapp);

        return ['sms' => $sms, 'whatsapp' => $whatsapp];
    }
}
