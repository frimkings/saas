<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $patientName;
    public string $clinic;
    public string $date;
    public string $time;
    public string $reason;

    public function __construct(string $patientName, string $clinic, string $date, string $time, string $reason)
    {
        $this->patientName = $patientName;
        $this->clinic      = $clinic;
        $this->date        = $date;
        $this->time        = $time;
        $this->reason      = $reason;
    }

    public function envelope()
    {
        return new Envelope(
            subject: "Appointment Confirmed — {$this->clinic}",
        );
    }

    public function content()
    {
        return new Content(
            markdown: 'emails.appointment-confirmation',
        );
    }

    public function attachments()
    {
        return [];
    }
}
