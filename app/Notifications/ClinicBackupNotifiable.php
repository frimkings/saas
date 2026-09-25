<?php

namespace App\Notifications;

use App\Models\Setting;
use Spatie\Backup\Notifications\Notifiable;

class ClinicBackupNotifiable extends Notifiable
{
    public function routeNotificationForMail(): string
    {
        return Setting::getSettings()->clinic_email
            ?? config('mail.from.address', 'admin@clinic.com');
    }
}
