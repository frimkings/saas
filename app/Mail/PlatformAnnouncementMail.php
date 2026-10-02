<?php

namespace App\Mail;

use App\Models\PlatformAnnouncement;
use Illuminate\Mail\Mailable;

/** An announcement from the platform to a clinic's owner or Super Admin. */
class PlatformAnnouncementMail extends Mailable
{
    public function __construct(public PlatformAnnouncement $announcement, public string $clinicName)
    {
    }

    public function build(): static
    {
        return $this->view('mail.platform-announcement');
    }
}
