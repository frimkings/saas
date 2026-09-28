<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\OwnerEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The one way the platform emails a clinic: to its owner, the billing email given when the
 * clinic subscribed, through the platform's Resend account. Each message has a key so it
 * goes once; a failed one can be sent again under the same key, up to MAX_ATTEMPTS times.
 * Sending never throws: an email problem must not undo the action that caused it.
 */
class OwnerMailer
{
    public const MAX_ATTEMPTS = 3;

    public static function ownerEmail(Clinic $clinic): ?string
    {
        $email = strtolower(trim((string) $clinic->billing_email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @param  callable(): Mailable  $mail  built only when the email is actually going out
     * @return string the message's status: sent, failed or skipped
     */
    public function send(Clinic $clinic, string $kind, string $key, string $subject, callable $mail): string
    {
        $row = OwnerEmail::firstOrNew(['dedupe_key' => $clinic->id . ':' . $key]);
        if ($row->exists && ($row->status !== OwnerEmail::FAILED || $row->attempts >= self::MAX_ATTEMPTS)) {
            return $row->status;
        }

        $to = self::ownerEmail($clinic);
        $row->fill(['clinic_id' => $clinic->id, 'kind' => $kind, 'recipient' => $to, 'subject' => $subject]);
        if (! $to) {
            $row->fill(['status' => OwnerEmail::SKIPPED, 'error' => 'The clinic has no owner email.'])->save();
            return $row->status;
        }

        $row->attempts++;
        try {
            Mail::to($to)->send($mail()->subject($subject));
            $row->fill(['status' => OwnerEmail::SENT, 'error' => null, 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $row->fill(['status' => OwnerEmail::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('Owner email failed.', ['clinic_id' => $clinic->id, 'kind' => $kind, 'error' => $e->getMessage()]);
        }
        $row->save();

        return $row->status;
    }
}
