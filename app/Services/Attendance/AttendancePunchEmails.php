<?php

namespace App\Services\Attendance;

use App\Mail\OwnerNoticeMail;
use App\Models\AttendanceSetting;
use App\Models\AttendanceStaff;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use App\Services\LicenseService;
use App\Services\OwnerMailer;
use App\Support\Feature;
use App\Support\Tenancy\ActAsClinic;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Clock-in emails, when the owner has ticked them under Set up → Clock-in emails. A punch from a
 * terminal or a phone is emailed to the owner (and any extra addresses) as it arrives, or gathered
 * into one summary in the evening; each person can be sent their own times too. Only punches that
 * are happening now are emailed: a terminal sending what it kept while it was cut off, an uploaded
 * report, or the demo, never send any. A manager's correction tells the person whose time it was.
 *
 * Emails go once the terminal or phone has had its answer, so a slow email never holds up a punch.
 */
class AttendancePunchEmails
{
    /** A punch older than this when it arrives was kept on a terminal that was cut off: not emailed. */
    public const FRESH_MINUTES = 15;

    /** The evening summary goes from this hour, clinic time. */
    public const SUMMARY_HOUR = 20;

    /** Extra addresses the owner can add. */
    public const MAX_EXTRA = 5;

    public function __construct(private readonly OwnerMailer $mailer) {}

    /**
     * A terminal or phone punch has just been stored. $at is the branch's clock time; $how says where
     * it came from ('Front door terminal', 'Phone, 40 m from the branch').
     */
    public function punched(AttendanceStaff $staff, string $at, string $how): void
    {
        [$clinic, $branch] = $this->place();
        // Someone new on the terminal has just been added, followed by default.
        if (! $clinic || ! $branch || ! ($staff->is_tracked ?? true)) {
            return;
        }
        $settings = AttendanceSetting::current();
        if (! $settings->punch_emails || ($settings->punch_email_mode === 'daily' && ! $settings->punch_email_staff)) {
            return;
        }
        $time = CarbonImmutable::parse($at, $this->timezone($clinic));
        $minutesAgo = $time->diffInMinutes(CarbonImmutable::now($this->timezone($clinic)), false);
        if ($minutesAgo > self::FRESH_MINUTES || $minutesAgo < -5) {
            return;
        }

        $staffId = $staff->id;
        $this->later($clinic, $branch, fn () => $this->sendPunch(AttendanceStaff::query()->find($staffId), $time, $how, $clinic, $branch));
    }

    /** A manager added, moved or removed one of someone's punches: they are told, with the reason. */
    public function corrected(AttendanceStaff $staff, string $what, string $reason): void
    {
        [$clinic, $branch] = $this->place();
        if (! $clinic || ! $branch) {
            return;
        }
        $settings = AttendanceSetting::current();
        if (! $settings->punch_emails || ! $settings->punch_email_staff || ! ($email = $this->staffEmail($staff))) {
            return;
        }
        $by = auth()->user()?->name;
        $key = 'attendance-fix:'.$staff->id.':'.md5($what.'|'.$reason.'|'.now()->format('YmdHis'));
        $this->later($clinic, $branch, fn () => $this->mailer->sendToAddress($clinic, $email, 'attendance_punch_corrected', $key, 'Your attendance was corrected - '.$clinic->name,
            fn () => new OwnerNoticeMail($clinic->name, 'Your attendance was corrected', "A manager changed your clock times: {$what}.",
                array_filter(['Change' => $what, 'Reason' => $reason, 'By' => $by, 'Branch' => $branch->name]),
                'My attendance', route('user.profile'), 'If this looks wrong, speak to your manager.', $this->staffFooter())));
    }

    /**
     * The evening summaries that are due: for each branch sending one, the day's clock-ins once it is
     * SUMMARY_HOUR there, else (missed while the app was asleep) yesterday's. Returns how many went.
     */
    public function sendSummaries(Clinic $clinic, ?CarbonImmutable $now = null): int
    {
        $sent = 0;
        ActAsClinic::eachBranch($clinic, function (Branch $branch) use ($clinic, $now, &$sent) {
            if (! LicenseService::has(Feature::ATTENDANCE)) {
                return;
            }
            $settings = AttendanceSetting::query()->first();
            if (! $settings?->punch_emails || $settings->punch_email_mode !== 'daily') {
                return;
            }
            $local = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone($clinic));
            $day = ($local->hour >= self::SUMMARY_HOUR ? $local : $local->subDay())->startOfDay();
            if ($settings->punch_emails_since && $day->format('Y-m-d') < $settings->punch_emails_since->format('Y-m-d')) {
                return;
            }
            $sent += $this->sendSummary($clinic, $branch, $settings, $day);
        });

        return $sent;
    }

    /** What a punch was: in or out, and against the day's hours. Null for a second tap of the same punch. */
    public function describe(AttendanceStaff $staff, CarbonImmutable $at): ?array
    {
        $today = $at->startOfDay();
        $row = app(AttendanceAnalyzer::class)->forStaff([$staff->id])->range($today->subDay(), $today, 'partial')['rows'][0] ?? null;
        if (! $row) {
            return null;
        }
        $minutes = AttendanceAnalyzer::minutesOf($at->format('H:i'));
        // Today's own punches, then last night's shift (whose morning punches are written past midnight).
        foreach ([[$today, 0], [$today->subDay(), 1440]] as [$day, $shift]) {
            $info = $row['days'][$day->format('Y-m-d')] ?? null;
            $time = AttendanceAnalyzer::clock($minutes + $shift);
            foreach ($info['punches'] ?? [] as $i => $punch) {
                if ($punch['time'] !== $time) {
                    continue;
                }
                $hours = $info['hours'];
                $late = $i === 0 && $punch['kind'] === 'in' ? (int) $info['late_minutes'] : 0;
                $early = $punch['kind'] === 'out' && $hours ? max(0, AttendanceAnalyzer::minutesOf($hours['end']) - ($minutes + $shift)) : 0;

                return ['kind' => $punch['kind'], 'first' => $i === 0, 'late' => $late, 'early' => $early, 'hours' => $hours];
            }
        }

        return null;
    }

    private function sendPunch(?AttendanceStaff $staff, CarbonImmutable $at, string $how, Clinic $clinic, Branch $branch): void
    {
        $what = $staff ? $this->describe($staff, $at) : null;
        if (! $what) {
            return;
        }
        $settings = AttendanceSetting::current();
        $in = $what['kind'] === 'in';
        $clock = $at->format('g:i A');
        $verb = $in ? 'clocked in' : 'clocked out';
        $status = $this->status($what);
        $details = array_filter([
            'Staff member' => $staff->name,
            $in ? 'Clocked in' : 'Clocked out' => $at->format('D j M Y, ').$clock,
            'Expected' => $what['hours'] ? trim(($what['hours']['shift'] ?? '').' '.AttendanceAnalyzer::time($what['hours']['start']).' to '.AttendanceAnalyzer::time($what['hours']['end'])) : 'Not a working day',
            'Status' => $status,
            'Branch' => $branch->name,
            'How' => $how,
        ]);
        $key = 'attendance-punch:'.$staff->id.':'.$at->format('YmdHi');

        $toOwner = $settings->punch_email_mode === 'each' && ($settings->punch_email_when !== 'late_early' || $what['late'] > 0 || $what['early'] > 0);
        if ($toOwner) {
            $subject = "{$staff->name} {$verb} at {$clock}";
            $mail = fn (?string $footer) => fn () => new OwnerNoticeMail($clinic->name, $subject, "{$staff->name} {$verb} at {$clock}".($status ? ": {$status}." : '.'), $details,
                'Open attendance', route('admin.attendance'), null, $footer);
            $this->mailer->send($clinic, 'attendance_punch', $key.':owner', $subject.' - '.$clinic->name, $mail(null));
            foreach ($this->extraAddresses($settings) as $address) {
                $this->mailer->sendToAddress($clinic, $address, 'attendance_punch', $key.':'.md5($address), $subject.' - '.$clinic->name,
                    $mail('Sent because the clinic owner added this address for clock-in emails. Ask them to take it off to stop these.'));
            }
        }

        if ($settings->punch_email_staff && $email = $this->staffEmail($staff)) {
            $heading = "You {$verb} at {$clock}";
            $this->mailer->sendToAddress($clinic, $email, 'attendance_punch_staff', $key.':staff', $heading.' - '.$clinic->name,
                fn () => new OwnerNoticeMail($clinic->name, $heading, "Your ".($in ? 'clock-in' : 'clock-out')." was received".($status ? ": {$status}." : '.'),
                    array_diff_key($details, ['Staff member' => 1]), 'My attendance', route('user.profile'),
                    'Wrong time, or not you? Tell your manager; corrections are made in the app.', $this->staffFooter()));
        }
    }

    /** 'Late by 12 min', 'On time', 'Left 1 h 30 min before the end of the day', or null when there is nothing to say. */
    private function status(array $what): ?string
    {
        if ($what['kind'] === 'in') {
            if (! $what['first'] || ! $what['hours']) {
                return $what['first'] ? 'On a day off' : 'Back in';
            }

            return $what['late'] ? 'Late by '.AttendanceAnalyzer::duration($what['late']) : 'On time';
        }

        return $what['early'] ? AttendanceAnalyzer::duration($what['early']).' before the end of the day' : null;
    }

    private function sendSummary(Clinic $clinic, Branch $branch, AttendanceSetting $settings, CarbonImmutable $day): int
    {
        $key = $day->format('Y-m-d');
        $dedupe = 'attendance-summary:'.$branch->id.':'.$key;
        // Already gone (or skipped for want of an owner email): the hourly run moves on.
        if (\App\Models\OwnerEmail::query()->where('dedupe_key', $clinic->id.':'.$dedupe.':owner')->whereIn('status', [\App\Models\OwnerEmail::SENT, \App\Models\OwnerEmail::SKIPPED])->exists()) {
            return 0;
        }
        $report = app(AttendanceAnalyzer::class)->range($day, $day, 'partial');
        $lines = [];
        $counts = ['in' => 0, 'late' => 0, 'absent' => 0, 'early' => 0];
        foreach ($report['rows'] as $row) {
            $info = $row['days'][$key];
            if (! $row['staff']->is_tracked || in_array($info['status'], ['none', 'off', 'holiday'], true)) {
                continue;
            }
            $punches = $info['punches'];
            $last = $punches ? end($punches) : null;
            $early = $last && $last['kind'] === 'out' && $info['hours'] ? max(0, AttendanceAnalyzer::minutesOf($info['hours']['end']) - AttendanceAnalyzer::minutesOf($last['time'])) : 0;
            $counts['in'] += $punches ? 1 : 0;
            $counts['late'] += $info['status'] === 'late' ? 1 : 0;
            $counts['absent'] += $info['status'] === 'absent' ? 1 : 0;
            $counts['early'] += $early ? 1 : 0;
            if ($settings->punch_email_when === 'late_early' && $info['status'] !== 'late' && $info['status'] !== 'absent' && ! $early) {
                continue;
            }
            $lines[$row['staff']->name] = match (true) {
                $info['status'] === 'leave' => 'On leave',
                $info['status'] === 'absent' => 'Absent',
                ! $punches => 'Not in',
                default => 'In '.AttendanceAnalyzer::time($info['in'])
                    .($info['late_minutes'] ? ' (late by '.AttendanceAnalyzer::duration($info['late_minutes']).')' : '')
                    .($last['kind'] === 'out' ? ' · Out '.AttendanceAnalyzer::time($last['time']).($early ? ' ('.AttendanceAnalyzer::duration($early).' early)' : '') : ' · not clocked out'),
            };
        }
        if (! $lines && ! $counts['in']) {
            return 0;
        }
        $shown = array_slice($lines, 0, 60, true);
        if (count($lines) > 60) {
            $shown['And'] = (count($lines) - 60).' more: see the Attendance page';
        }
        $heading = 'Clock-ins for '.$day->format('D j M');
        $intro = "{$counts['in']} came in at {$branch->name}: {$counts['late']} late, {$counts['early']} left before the end of the day, {$counts['absent']} absent."
            .($settings->punch_email_when === 'late_early' ? ' Only those late, absent or leaving early are listed.' : '');
        $mail = fn (?string $footer) => fn () => new OwnerNoticeMail($clinic->name, $heading, $intro, $shown, 'Open attendance', route('admin.attendance'), null, $footer);
        $sent = (int) ($this->mailer->send($clinic, 'attendance_summary', $dedupe.':owner', $heading.' - '.$branch->name, $mail(null)) === 'sent');
        foreach ($this->extraAddresses($settings) as $address) {
            $sent += (int) ($this->mailer->sendToAddress($clinic, $address, 'attendance_summary', $dedupe.':'.md5($address), $heading.' - '.$branch->name,
                $mail('Sent because the clinic owner added this address for clock-in emails. Ask them to take it off to stop these.')) === 'sent');
        }

        return $sent;
    }

    /** @return list<string> */
    private function extraAddresses(AttendanceSetting $settings): array
    {
        return array_slice(array_values(array_filter((array) $settings->punch_email_to, fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL))), 0, self::MAX_EXTRA);
    }

    private function staffEmail(AttendanceStaff $staff): ?string
    {
        return $staff->user_id ? User::query()->whereKey($staff->user_id)->value('email') : null;
    }

    private function staffFooter(): string
    {
        return 'Sent to you because your clinic emails staff their own clock-in and clock-out times. Your manager can switch this off.';
    }

    /** @return array{0: ?Clinic, 1: ?Branch} */
    private function place(): array
    {
        $context = app(TenantContext::class);
        $clinic = $context->clinic();

        return [$clinic && LicenseService::has(Feature::ATTENDANCE) ? $clinic : null, $context->branch()];
    }

    private function timezone(Clinic $clinic): string
    {
        return $clinic->default_timezone ?: config('app.timezone');
    }

    /** After the answer has gone to the terminal or phone, back in this branch; never lets an email problem surface. */
    private function later(Clinic $clinic, Branch $branch, callable $work): void
    {
        app()->terminating(function () use ($clinic, $branch, $work) {
            try {
                ActAsClinic::run($clinic, $branch, fn () => $work());
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
