<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * A short notice to a clinic owner: what happened, the details, and a button to the screen
 * where they act on it. Approving always happens in the app, never from the email.
 */
class OwnerNoticeMail extends Mailable
{
    /**
     * @param  array<string, string>  $details  label => value
     */
    public function __construct(
        public string $clinicName,
        public string $heading,
        public string $intro,
        public array $details,
        public string $buttonLabel,
        public string $buttonUrl,
        public ?string $footnote = null,
        /** Replaces the owner-email footer, e.g. on emails to the platform's own inbox. */
        public ?string $footer = null,
    ) {}

    public function build(): static
    {
        return $this->view('mail.owner-notice');
    }
}
