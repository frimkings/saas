<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The daily, weekly or monthly sales summary to the clinic owner. See App\Services\OwnerSummaryService. */
class OwnerSummaryMail extends Mailable
{
    public function __construct(public array $summary) {}

    public function build(): static
    {
        return $this->view('mail.owner-summary');
    }
}
