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

        // Each link only for those who can open its page (the same screen map the routes use).
        $may = fn (string $route) => \App\Support\Access\Access::allowsRoute($user, $route);
        $campaigns = LicenseService::has(Feature::SMS_CAMPAIGNS);
        // Messages to patients (templates, credits, WhatsApp, logs) come with Feature::PATIENT_MESSAGES.
        $messages = LicenseService::has(Feature::PATIENT_MESSAGES);

        return array_values(array_filter([
            $may('admin.messages') && $messages ? ['admin.messages', 'Messages', 'fas fa-comment-dots text-primary'] : null,
            // Credits and logs serve broadcasts too, so they come with either.
            $may('admin.sms-settings') && ($messages || $campaigns) ? ['admin.sms-settings', 'SMS Credits & Sending', 'fas fa-sms text-info'] : null,
            $may('admin.whatsapp-settings') && $messages ? ['admin.whatsapp-settings', 'WhatsApp', 'fab fa-whatsapp text-success'] : null,
            $may('admin.broadcast') && $campaigns ? ['admin.broadcast', 'Broadcast', 'fas fa-bullhorn text-warning'] : null,
            $may('admin.patient-recall') && $campaigns ? ['admin.patient-recall', 'Patient Recall', 'fas fa-user-clock text-purple'] : null,
            $may('admin.sms-logs') && ($messages || $campaigns) ? ['admin.sms-logs', 'SMS Logs', 'fas fa-list text-secondary'] : null,
        ]));
    }

    /** Whether the current page belongs to the Communications menu. */
    public static function active(): bool
    {
        return request()->routeIs('admin.messages', 'admin.sms-settings', 'admin.whatsapp-settings', 'admin.broadcast', 'admin.patient-recall', 'admin.sms-logs');
    }
}
