<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
class SubscriptionBillingMail extends Mailable
{
 use Queueable,SerializesModels;
 public function __construct(public array $messageData){}
 public function build():static{return $this->subject($this->messageData['subject'])->view('mail.subscription-billing');}
}
