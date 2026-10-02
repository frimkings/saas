<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\OwnerEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The one way the platform sends email: to a clinic's owner (the billing email given when the
 * clinic subscribed), or to the platform's own requests inbox, through the platform's Resend
 * account. An owner who replies reaches the platform's support email (Platform → Support); a
 * reply from the requests inbox goes to the clinic's owner. Each message has a key so it goes
 * once; a failed one can be sent again under the same key, up to MAX_ATTEMPTS times. Sending
 * never throws: an email problem must not undo the action that caused it.
 */
class OwnerMailer
{
    public const MAX_ATTEMPTS = 3;

    public static function ownerEmail(Clinic $clinic): ?string
    {
        return self::valid($clinic->billing_email);
    }

    /** Where owners' replies go: the support email set under Platform → Support. */
    public static function supportEmail(): ?string
    {
        return self::valid(\App\Models\PlatformSetting::support()['email'] ?? null);
    }

    /**
     * @param  callable(): Mailable  $mail  built only when the email is actually going out
     * @return string the message's status: sent, failed or skipped
     */
    public function send(Clinic $clinic, string $kind, string $key, string $subject, callable $mail): string
    {
        return $this->deliver($clinic->id, $clinic->id . ':' . $key, self::ownerEmail($clinic), 'The clinic has no owner email.', $kind, $subject, $mail, self::supportEmail());
    }

    /** Email one address at a clinic (e.g. a Super Admin), with the same once-only key and retries. */
    public function sendToAddress(Clinic $clinic, ?string $email, string $kind, string $key, string $subject, callable $mail): string
    {
        return $this->deliver($clinic->id, $clinic->id . ':' . $key, self::valid($email), 'Not a valid email address.', $kind, $subject, $mail, self::supportEmail());
    }

    /** Email the platform's requests inbox, optionally about one clinic. */
    public function sendToPlatform(?Clinic $clinic, ?string $inbox, string $kind, string $key, string $subject, callable $mail): string
    {
        return $this->deliver($clinic?->id, 'platform:' . $key, self::valid($inbox), 'No support email or requests inbox is set under Platform → Support.', $kind, $subject, $mail,
            $clinic ? self::ownerEmail($clinic) : null);
    }

    private function deliver(?int $clinicId, string $dedupeKey, ?string $to, string $noRecipient, string $kind, string $subject, callable $mail, ?string $replyTo = null): string
    {
        $row = OwnerEmail::firstOrNew(['dedupe_key' => $dedupeKey]);
        if ($row->exists && ($row->status !== OwnerEmail::FAILED || $row->attempts >= self::MAX_ATTEMPTS)) {
            return $row->status;
        }

        $row->fill(['clinic_id' => $clinicId, 'kind' => $kind, 'recipient' => $to, 'subject' => $subject]);
        if (! $to) {
            $row->fill(['status' => OwnerEmail::SKIPPED, 'error' => $noRecipient])->save();
            return $row->status;
        }

        $row->attempts++;
        try {
            $message = $mail()->subject($subject);
            if ($replyTo) $message->replyTo($replyTo);
            Mail::to($to)->send($message);
            $row->fill(['status' => OwnerEmail::SENT, 'error' => null, 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $row->fill(['status' => OwnerEmail::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('Email failed.', ['clinic_id' => $clinicId, 'kind' => $kind, 'error' => $e->getMessage()]);
        }
        $row->save();

        return $row->status;
    }

    private static function valid(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
