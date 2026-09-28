<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
class SubscriptionBillingMail extends Mailable
{
 use Queueable,SerializesModels;
 public function __construct(public array $messageData){}
 public function build():static{
  // Replies reach the platform's support email (Platform → Support).
  if($support=\App\Services\OwnerMailer::supportEmail())$this->replyTo($support);
  return $this->subject($this->messageData['subject'])->view('mail.subscription-billing');
 }
}
