<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\Attendance\AttendancePunchEmails;
use Illuminate\Console\Command;

/**
 * Emails staff still clocked in whose shift ends soon, where the owner has clock-out reminders on.
 * Hosted it runs hourly (the server sleeps between runs to keep the bill down), so it looks an hour
 * ahead; an offline install runs it every 5 minutes and looks 5 minutes ahead.
 */
class SendClockOutRemindersCommand extends Command
{
    protected $signature = 'attendance:clock-out-reminders {--lead= : Minutes ahead to look (default 60 hosted, 5 offline)}';

    protected $description = 'Remind staff by email to clock out before their shift ends.';

    public function handle(AttendancePunchEmails $emails): int
    {
        $lead = (int) ($this->option('lead') ?: (config('tenancy.enabled') ? 60 : 5));
        Clinic::where('status', 'active')->orderBy('id')->each(function (Clinic $clinic) use ($emails, $lead) {
            try {
                if ($sent = $emails->sendClockOutReminders($clinic, $lead)) {
                    $this->line("{$clinic->name}: {$sent} clock-out reminder(s)");
                }
            } catch (\Throwable $e) {
                report($e);
                $this->warn("{$clinic->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
