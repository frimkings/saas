<?php

namespace App\Support;

use App\Services\LicenseService;

/**
 * The Communications menu (clinic sidebar and the optical shop's): only the pages the
 * signed-in person can open. Messages, SMS credits, WhatsApp and Broadcast are the Super
 * Admin's. Credits and logs are on every plan (everyday messages need them); Broadcast and
 * Patient Recall come with SMS campaigns.
 */
final class CommunicationsNavigation
{
    /** @return list<array{0: string, 1: string, 2: string}> [route, label, icon] */
    public static function links(): array
    {
        $user = auth()->user();
        if (! $user) return [];

        $superAdmin = $user->hasRole('Super Admin');
        $manager = $superAdmin || $user->hasRole('Manager');
        $campaigns = LicenseService::has(Feature::SMS_CAMPAIGNS);
        // Messages to patients (templates, credits, WhatsApp, logs) come with Feature::PATIENT_MESSAGES.
        $messages = LicenseService::has(Feature::PATIENT_MESSAGES);

        return array_values(array_filter([
            $superAdmin && $messages ? ['admin.messages', 'Messages', 'fas fa-comment-dots text-primary'] : null,
            // Credits and logs serve broadcasts too, so they come with either.
            $superAdmin && ($messages || $campaigns) ? ['admin.sms-settings', 'SMS Credits & Sending', 'fas fa-sms text-info'] : null,
            $superAdmin && $messages ? ['admin.whatsapp-settings', 'WhatsApp', 'fab fa-whatsapp text-success'] : null,
            $superAdmin && $campaigns ? ['admin.broadcast', 'Broadcast', 'fas fa-bullhorn text-warning'] : null,
            $manager && $campaigns ? ['admin.patient-recall', 'Patient Recall', 'fas fa-user-clock text-purple'] : null,
            $manager && ($messages || $campaigns) ? ['admin.sms-logs', 'SMS Logs', 'fas fa-list text-secondary'] : null,
        ]));
    }

    /** Whether the current page belongs to the Communications menu. */
    public static function active(): bool
    {
        return request()->routeIs('admin.messages', 'admin.sms-settings', 'admin.whatsapp-settings', 'admin.broadcast', 'admin.patient-recall', 'admin.sms-logs');
    }
}
