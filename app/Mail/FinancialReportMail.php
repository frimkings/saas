<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FinancialReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $report;

    public function __construct(array $report)
    {
        $this->report = $report;
    }

    public function build(): static
    {
        return $this
            ->subject($this->report['subject'])
            ->view('mail.financial-report');
    }
}
