<?php

namespace App\Notifications;

use App\Models\PlatformSetting;
use App\Models\Setting;
use Spatie\Backup\Notifications\Notifiable;

class ClinicBackupNotifiable extends Notifiable
{
    public function routeNotificationForMail(): string
    {
        // Multi-clinic backups cover every clinic's data, so they report to the platform, not a clinic.
        $email = config('tenancy.enabled')
            ? PlatformSetting::support()['email']
            : Setting::getSettings()->clinic_email;

        return $email ?? config('mail.from.address', 'admin@clinic.com');
    }
}
