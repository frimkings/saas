<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SpectaclesReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $patientName;
    public string $clinic;
    public string $orderId;

    public function __construct(string $patientName, string $clinic, string $orderId)
    {
        $this->patientName = $patientName;
        $this->clinic      = $clinic;
        $this->orderId     = $orderId;
    }

    public function envelope()
    {
        return new Envelope(
            subject: "Your Spectacles Are Ready — {$this->clinic}",
        );
    }

    public function content()
    {
        return new Content(
            markdown: 'emails.spectacles-ready',
        );
    }

    public function attachments()
    {
        return [];
    }
}
