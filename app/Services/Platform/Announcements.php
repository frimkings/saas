<?php

namespace App\Services\Platform;

use App\Mail\PlatformAnnouncementMail;
use App\Models\Clinic;
use App\Models\OwnerEmail;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementRecipient;
use App\Models\User;
use App\Services\OwnerMailer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

/**
 * Platform → Announcements. An announcement goes to the clinics its audience picks: by email to
 * each clinic's owner (billing email) and active Super Admins, and, when switched on, as a
 * banner the clinic's Super Admins see in the app until they dismiss it.
 *
 * Emails go through OwnerMailer (logged, once per address, retried up to 3 times) in small
 * batches: a few straight away, the rest every minute (platform:send-announcements), so a
 * long list never ties up a page or trips the email provider's rate limit.
 */
class Announcements
{
    /** Emails sent while the platform admin waits on the Send button. */
    public const SEND_NOW = 5;

    /** Emails per scheduled run. */
    public const PER_RUN = 30;

    public const SUBSCRIPTION_STATUSES = ['trial' => 'Trial', 'active' => 'Active', 'overdue' => 'Overdue', 'restricted' => 'Restricted', 'suspended' => 'Suspended'];

    public function __construct(private OwnerMailer $mailer)
    {
    }

    /**
     * The active clinics an audience picks. Chosen clinics, when given, win over the filters.
     *
     * @param  array{statuses?: array, plan_ids?: array, modes?: array, clinic_ids?: array}  $audience
     */
    public function clinicsFor(array $audience): Collection
    {
        $clinicIds = array_filter(array_map('intval', $audience['clinic_ids'] ?? []));
        $statuses  = array_values(array_intersect($audience['statuses'] ?? [], array_keys(self::SUBSCRIPTION_STATUSES)));
        $planIds   = array_filter(array_map('intval', $audience['plan_ids'] ?? []));
        $modes     = array_values(array_intersect($audience['modes'] ?? [], ['hosted', 'offline']));

        $query = Clinic::query()->where('status', 'active')->with('currentSubscription')->orderBy('name');
        if ($clinicIds) {
            return $query->whereIn('id', $clinicIds)->get();
        }

        return $query
            ->when($modes, fn ($q) => $q->whereIn('deployment_mode', $modes))
            ->get()
            ->filter(function (Clinic $clinic) use ($statuses, $planIds) {
                $subscription = $clinic->currentSubscription;
                if ($statuses && !in_array($subscription?->status, $statuses, true)) return false;
                if ($planIds && !in_array((int) $subscription?->subscription_plan_id, $planIds, true)) return false;

                return true;
            })
            ->values();
    }

    /**
     * Who at a clinic gets the email: the owner (billing email) and each active Super Admin.
     *
     * @return array<int, array{role: string, email: string, user_id: ?int}>
     */
    public function addressesFor(Clinic $clinic): array
    {
        $addresses = [];
        if ($owner = OwnerMailer::ownerEmail($clinic)) {
            $addresses[$owner] = ['role' => 'owner', 'email' => $owner, 'user_id' => null];
        }

        $admins = $clinic->users()
            ->wherePivot('status', 'active')
            ->wherePivot('clinic_role', 'Super Admin')
            ->get(['users.id', 'users.email']);
        foreach ($admins as $admin) {
            $email = strtolower(trim((string) $admin->email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($addresses[$email])) {
                $addresses[$email] = ['role' => 'super_admin', 'email' => $email, 'user_id' => $admin->id];
            }
        }

        return array_values($addresses);
    }

    /** Counts for the audience preview. */
    public function preview(array $audience): array
    {
        $clinics = $this->clinicsFor($audience);
        $owners = $admins = $noEmail = 0;
        foreach ($clinics as $clinic) {
            $addresses = collect($this->addressesFor($clinic));
            $owners += $addresses->where('role', 'owner')->count();
            $admins += $addresses->where('role', 'super_admin')->count();
            $noEmail += $addresses->isEmpty() ? 1 : 0;
        }

        return ['clinics' => $clinics->count(), 'owners' => $owners, 'admins' => $admins, 'noEmail' => $noEmail, 'names' => $clinics->pluck('name')->all()];
    }

    /**
     * Fix the recipients and start sending. Every chosen clinic gets a row (that is what the
     * banner looks for), plus one per email address when emails are on.
     */
    public function send(PlatformAnnouncement $announcement): void
    {
        DB::transaction(function () use ($announcement) {
            $announcement = PlatformAnnouncement::lockForUpdate()->findOrFail($announcement->id);
            if (!$announcement->isDraft()) {
                return; // already sent: never twice
            }

            foreach ($this->clinicsFor($announcement->audience ?? []) as $clinic) {
                $addresses = $announcement->send_email ? $this->addressesFor($clinic) : [];
                if (!$addresses) {
                    PlatformAnnouncementRecipient::create([
                        'platform_announcement_id' => $announcement->id, 'clinic_id' => $clinic->id, 'role' => 'none',
                        'status' => PlatformAnnouncementRecipient::SKIPPED,
                        'error' => $announcement->send_email ? 'No owner or Super Admin email.' : 'Banner only.',
                    ]);
                    continue;
                }
                foreach ($addresses as $address) {
                    PlatformAnnouncementRecipient::create($address + [
                        'platform_announcement_id' => $announcement->id, 'clinic_id' => $clinic->id,
                        'status' => PlatformAnnouncementRecipient::QUEUED,
                    ]);
                }
            }

            $announcement->update(['status' => PlatformAnnouncement::SENDING, 'sent_at' => now()]);
        });

        $this->deliver(self::SEND_NOW);
    }

    /** Send up to $limit queued emails, across announcements, oldest first. */
    public function deliver(int $limit = self::PER_RUN, int $pauseMs = 0): int
    {
        $rows = PlatformAnnouncementRecipient::with(['announcement', 'clinic'])
            ->where('status', PlatformAnnouncementRecipient::QUEUED)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $i => $row) {
            if ($i > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $announcement = $row->announcement;
            $key = 'announcement:' . $announcement->id . ':' . $row->email;
            $status = $this->mailer->sendToAddress($row->clinic, $row->email, 'announcement', $key, $announcement->subject,
                fn () => new PlatformAnnouncementMail($announcement, $row->clinic->name));

            $row->update([
                'status'  => $status,
                'error'   => $status === OwnerEmail::SENT ? null : OwnerEmail::where('dedupe_key', $row->clinic_id . ':' . $key)->value('error'),
                'sent_at' => $status === OwnerEmail::SENT ? now() : null,
            ]);
        }

        // Announcements with nothing left to send are done.
        PlatformAnnouncement::where('status', PlatformAnnouncement::SENDING)
            ->whereDoesntHave('recipients', fn ($q) => $q->where('status', PlatformAnnouncementRecipient::QUEUED))
            ->update(['status' => PlatformAnnouncement::SENT]);

        return $rows->count();
    }

    /** Put failed emails back in the queue (the mailer stops after 3 attempts per address). */
    public function retryFailed(PlatformAnnouncement $announcement): int
    {
        $count = $announcement->recipients()->where('status', PlatformAnnouncementRecipient::FAILED)->whereNotNull('email')
            ->update(['status' => PlatformAnnouncementRecipient::QUEUED, 'error' => null]);
        if ($count) {
            $announcement->update(['status' => PlatformAnnouncement::SENDING]);
            $this->deliver(self::SEND_NOW);
        }

        return $count;
    }

    /** Send the announcement to one address as a test (nothing is logged against any clinic). */
    public function sendTest(PlatformAnnouncement $announcement, string $email): void
    {
        Mail::to($email)->send((new PlatformAnnouncementMail($announcement, 'Sample Clinic'))->subject('[Test] ' . $announcement->subject));
    }

    /** The banner a user should see now, at this clinic, if any. */
    public function bannerFor(?User $user, ?int $clinicId): ?PlatformAnnouncement
    {
        if (!$user || !$clinicId) {
            return null;
        }
        $isClinicSuperAdmin = $user->hasRole('Super Admin') || DB::table('clinic_user')
            ->where('clinic_id', $clinicId)->where('user_id', $user->id)
            ->where('status', 'active')->where('clinic_role', 'Super Admin')->exists();
        if (!$isClinicSuperAdmin) {
            return null;
        }

        return PlatformAnnouncement::where('show_banner', true)
            ->whereIn('status', [PlatformAnnouncement::SENDING, PlatformAnnouncement::SENT])
            ->where(fn ($q) => $q->whereNull('banner_until')->orWhere('banner_until', '>=', today()->toDateString()))
            ->whereHas('recipients', fn ($q) => $q->where('clinic_id', $clinicId))
            ->whereNotExists(fn ($q) => $q->from('platform_announcement_dismissals')
                ->whereColumn('platform_announcement_dismissals.platform_announcement_id', 'platform_announcements.id')
                ->where('platform_announcement_dismissals.user_id', $user->id))
            ->latest('sent_at')
            ->first();
    }

    public function dismiss(PlatformAnnouncement $announcement, User $user): void
    {
        DB::table('platform_announcement_dismissals')->insertOrIgnore([
            'platform_announcement_id' => $announcement->id, 'user_id' => $user->id, 'dismissed_at' => now(),
        ]);
    }

    /**
     * The message as safe HTML: paragraphs, **bold**, "- " bullet lines and [CLINIC] for the
     * clinic's name. Nothing else the author types becomes markup.
     */
    public static function bodyHtml(string $body, string $clinicName): HtmlString
    {
        $text = str_replace('[CLINIC]', $clinicName, $body);
        $blocks = preg_split('/\R{2,}/', trim(str_replace("\r\n", "\n", $text)));
        $html = '';
        foreach ($blocks as $block) {
            $lines = preg_split('/\R/', $block);
            $isList = collect($lines)->every(fn ($line) => preg_match('/^\s*[-*]\s+/', $line));
            $format = fn (string $line) => preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($line));
            if ($isList) {
                $html .= '<ul style="margin:0 0 14px;padding-left:20px;line-height:1.6;color:#344054">'
                    . collect($lines)->map(fn ($line) => '<li>' . $format(preg_replace('/^\s*[-*]\s+/', '', $line)) . '</li>')->implode('')
                    . '</ul>';
            } else {
                $html .= '<p style="margin:0 0 14px;line-height:1.6;color:#344054">' . collect($lines)->map($format)->implode('<br>') . '</p>';
            }
        }

        return new HtmlString($html);
    }
}
