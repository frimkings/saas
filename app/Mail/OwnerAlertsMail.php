<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The morning alerts email to the clinic owner. See App\Services\OwnerAlertDigestService. */
class OwnerAlertsMail extends Mailable
{
    public function __construct(public array $alerts) {}

    public function build(): static
    {
        return $this->view('mail.owner-alerts');
    }
}
