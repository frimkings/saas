<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $patientName;
    public string $clinic;
    public string $amount;
    public string $transactionId;
    public string $paymentDate;

    public function __construct(string $patientName, string $clinic, string $amount, string $transactionId, string $paymentDate)
    {
        $this->patientName     = $patientName;
        $this->clinic          = $clinic;
        $this->amount          = $amount;
        $this->transactionId   = $transactionId;
        $this->paymentDate     = $paymentDate;
    }

    public function envelope()
    {
        return new Envelope(
            subject: "Payment Receipt — {$this->clinic}",
        );
    }

    public function content()
    {
        return new Content(
            markdown: 'emails.payment-receipt',
        );
    }

    public function attachments()
    {
        return [];
    }
}
