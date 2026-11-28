<?php

namespace App\Services;

use App\Mail\OwnerNoticeMail;
use App\Models\Clinic;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One-time links to choose a new password, emailed to the account's own address. Online, someone who
 * forgot their password gets one at once (no approval), and an owner or manager can send one from the
 * Staff page; a manager approving a request sends one too. The link works once, for LINK_MINUTES; the
 * clinic owner is told, so a reset nobody asked for is noticed. Offline installs (often no email) keep
 * the manager's approval and code.
 */
class PasswordResetLinks
{
    /** Someone asking again this soon gets no second email: stops an inbox being flooded. */
    public const RESEND_AFTER_MINUTES = 2;

    public function __construct(private readonly OwnerMailer $mailer, private readonly OwnerAlerts $alerts) {}

    /** Emails sent straight from "Forgot password" (the hosted service, where mail is set up). */
    public static function selfService(): bool
    {
        return (bool) config('tenancy.enabled');
    }

    /** "Forgot password": a link to the account's inbox, unless one went in the last few minutes. */
    public function forgot(User $user): void
    {
        $recent = PasswordResetRequest::withoutGlobalScopes()->where('email', $user->email)->where('status', 'approved')
            ->whereNotNull('link_hash')->where('updated_at', '>', now()->subMinutes(self::RESEND_AFTER_MINUTES))->exists();
        if ($recent) {
            return;
        }
        $request = $this->open($user, null, 'Emailed straight to the account (Forgot password).');
        $this->email($request, $user, 'You asked to reset your password. Open the button below to choose a new one.');
        $this->alerts->passwordResetEmailed($user, null);
    }

    /** An owner or manager sends the staff member a link instead of choosing a password for them. */
    public function sendFor(User $user, User $by): string
    {
        $request = $this->open($user, $by, "Emailed by {$by->name} from the Staff page.");
        $status = $this->email($request, $user, "{$by->name} sent you this so you can choose a new password yourself.");
        $this->alerts->passwordResetEmailed($user, $by);

        return $status;
    }

    /** Email the link for an approved request; returns sent, failed or skipped. */
    public function email(PasswordResetRequest $request, User $user, string $intro): string
    {
        $token = $request->issueLink();
        $clinic = $this->clinicFor($user);
        $name = $clinic?->name ?? config('app.name');
        $mail = fn () => new OwnerNoticeMail($name, 'Reset your password', "Hello {$user->name}. {$intro}",
            ['Account' => $user->email, 'Link works for' => PasswordResetRequest::LINK_MINUTES.' minutes, once'],
            'Choose a new password', route('password.offline.link', $token),
            'If you did not ask for this, ignore this email: your password stays as it is. Tell your manager.',
            'Sent to you because a password reset was asked for on your account.');
        $key = 'password-reset-link:'.$request->id.':'.now()->format('YmdHisv');

        if ($clinic) {
            return $this->mailer->sendToAddress($clinic, $user->email, 'password_reset_link', $key, 'Reset your password - '.$name, $mail);
        }
        try {
            Mail::to($user->email)->send($mail()->subject('Reset your password - '.$name));

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('Password reset link email failed.', ['error' => $e->getMessage()]);

            return 'failed';
        }
    }

    /** A new approved request for the account; older open ones are closed so only the newest link works. */
    private function open(User $user, ?User $by, string $note): PasswordResetRequest
    {
        PasswordResetRequest::withoutGlobalScopes()->where('email', $user->email)->whereIn('status', ['pending', 'approved'])
            ->update(['status' => 'completed', 'code_hash' => null, 'link_hash' => null, 'admin_note' => 'Replaced by a newer reset.']);

        return PasswordResetRequest::create(['email' => $user->email, 'status' => 'approved', 'approved_by' => $by?->id,
            'admin_note' => $note, 'actioned_at' => now()]);
    }

    /** The clinic the email is sent for: the one in use, else the person's first active clinic. */
    private function clinicFor(User $user): ?Clinic
    {
        return app(TenantContext::class)->clinic()
            ?? $user->clinics()->where('clinics.status', 'active')->wherePivot('status', 'active')->orderBy('clinics.id')->first();
    }
}
