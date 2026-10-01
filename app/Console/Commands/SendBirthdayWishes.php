<?php

namespace App\Console\Commands;

use App\Support\Messaging\AutomaticChannels;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBirthdayWishes extends Command
{
    protected $signature   = 'sms:birthday-wishes {--dry-run : Preview patients without sending}';
    protected $description = 'Send birthday SMS to patients whose birthday is today.';

    public function handle(): int
    {
        $s = Setting::getSettings();

        if (isset($s->sms_enabled) && !$s->sms_enabled) {
            $this->warn('SMS notifications are paused. Enable them in Communications → SMS Credits & Sending.');
            return self::SUCCESS;
        }

        $today  = now();
        $filter = $s->birthday_sms_filter ?? 'all';

        $query = Patient::whereNotNull('dob')
            ->whereNotNull('contact')
            ->where('contact', '!=', '')
            ->whereMonth('dob', $today->month)
            ->whereDay('dob', $today->day);

        if ($filter === 'this_year') {
            $query->whereHas('consultations', fn ($q) =>
                $q->whereYear('created_at', $today->year)
            );
        } elseif ($filter === 'last_24_months') {
            $query->whereHas('consultations', fn ($q) =>
                $q->where('created_at', '>=', $today->copy()->subMonths(24))
            );
        } elseif ($filter === 'custom') {
            $months = (int) ($s->birthday_sms_custom_months ?? 24);
            $query->whereHas('consultations', fn ($q) =>
                $q->where('created_at', '>=', $today->copy()->subMonths($months))
            );
        }

        $patients = $query->get(['id', 'name', 'contact', 'dob']);

        if ($patients->isEmpty()) {
            $this->info('No patient birthdays today.');
            return self::SUCCESS;
        }

        $clinic      = $s->clinic_name ?? 'the clinic';
        $sms         = new SmsService();
        $wa          = new WhatsAppService();
        $bulkChannel = $s->whatsapp_bulk_channel ?? 'sms';
        ['sms' => $useSms, 'whatsapp' => $useWhatsApp] = AutomaticChannels::pick($bulkChannel, $s, $s->whatsapp_birthday_template ?? null);
        $sent        = 0;
        $failed      = 0;
        $dryRun      = $this->option('dry-run');

        $this->info(($dryRun ? '[DRY RUN] ' : '') .
            "Found {$patients->count()} birthday patient(s) for " . $today->format('d M Y'));

        foreach ($patients as $patient) {
            $msg = SmsTemplate::render('birthday_wishes', [
                '[NAME]'   => $patient->name,
                '[CLINIC]' => $clinic,
            ]);

            if (!$msg) {
                $this->warn("  Skipping {$patient->name} — birthday_wishes template is empty.");
                continue;
            }

            if ($dryRun) {
                $this->line("  → {$patient->name} ({$patient->contact}): {$msg}");
                continue;
            }

            $anySent = false;

            if ($useSms && !empty($s->sms_enabled) && $s->sms_enabled) {
                $result = $sms->send($patient->contact, $msg, $patient->id, 'birthday_wishes');
                if ($result['success']) { $anySent = true; }
            }

            if ($useWhatsApp && !empty($s->whatsapp_enabled) && $s->whatsapp_enabled) {
                $templateName = $s->whatsapp_birthday_template ?? '';
                if ($templateName) {
                    $result = $wa->sendTemplate(
                        $patient->contact, $templateName, $s->whatsapp_appt_template_lang ?? 'en',
                        [$patient->name, $clinic], $patient->id, 'birthday_wishes'
                    );
                    if ($result['success']) { $anySent = true; }
                }
            }

            if ($anySent) {
                $this->info("  Sent to {$patient->name} ({$patient->contact})");
                $sent++;
            } else {
                $this->error("  Failed for {$patient->name}");
                $failed++;
            }
        }

        if (!$dryRun) {
            Log::info("sms:birthday-wishes — sent: {$sent}, failed: {$failed}, date: " . $today->toDateString());
            $this->info("Done. Sent: {$sent} | Failed: {$failed}");
        }

        return self::SUCCESS;
    }
}
