<?php

namespace App\Support\Messaging;

use App\Models\Setting;
use App\Services\Messaging\SmsCreditService;
use App\Services\Messaging\SmsCredentialResolver;
use App\Services\Messaging\SmsNotConfiguredException;

/**
 * Whether this clinic can send SMS right now: SMS not paused, a gateway configured,
 * and (for hosted clinics on the platform gateway) prepaid credits left. Screens use it
 * to show SMS buttons only when a send can succeed; WhatsApp links work regardless.
 */
final class SmsAvailability
{
    /** @return array{available: bool, reason: ?string, credits: ?int} */
    public static function check(?Setting $settings = null): array
    {
        $settings ??= Setting::getSettings();
        if (isset($settings->sms_enabled) && ! $settings->sms_enabled) {
            return ['available' => false, 'reason' => 'SMS is paused in Communications → SMS Credits & Sending.', 'credits' => null];
        }
        try {
            $credentials = app(SmsCredentialResolver::class)->resolve($settings);
        } catch (SmsNotConfiguredException $e) {
            return ['available' => false, 'reason' => $e->getMessage(), 'credits' => null];
        }
        if (! $credentials->platformManaged) {
            return ['available' => true, 'reason' => null, 'credits' => null];
        }
        // Same clinic lookup SmsService uses when it charges credits.
        $clinicId = \App\Models\SmsLog::clinicIdForWrite();
        $credits = $clinicId ? app(SmsCreditService::class)->balance($clinicId) : 0;

        return $credits > 0
            ? ['available' => true, 'reason' => null, 'credits' => $credits]
            : ['available' => false, 'reason' => 'No SMS credits left. Buy an SMS bundle in Communications → SMS Credits & Sending.', 'credits' => 0];
    }
}
