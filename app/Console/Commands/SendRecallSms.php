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

class SendRecallSms extends Command
{
    protected $signature   = 'sms:recall-patients {--dry-run : Preview without sending}';
    protected $description = 'Send recall SMS to patients inactive for the configured number of months.';

    public function handle(): int
    {
        $s = Setting::getSettings();

        if (isset($s->sms_enabled) && !$s->sms_enabled) {
            $this->warn('SMS notifications are paused. Enable them in Settings → SMS Settings.');
            return self::SUCCESS;
        }

        if (empty($s->recall_sms_enabled)) {
            $this->warn('Patient recall SMS is disabled. Enable it in Settings → SMS Templates.');
            return self::SUCCESS;
        }

        $months  = max(1, (int) ($s->recall_months ?? 12));
        $cutoff  = now()->subMonths($months);
        $clinic  = $s->clinic_name ?? 'the clinic';
        $dryRun  = $this->option('dry-run');

        // Patients whose last consultation was before the cutoff,
        // have a contact number, and haven't already received a recall SMS this cycle.
        $patients = Patient::whereNotNull('contact')
            ->where('contact', '!=', '')
            ->where(function ($q) use ($cutoff) {
                // recall_sms_sent_at is null OR was sent before the cutoff (so we don't spam monthly)
                $q->whereNull('recall_sms_sent_at')
                  ->orWhere('recall_sms_sent_at', '<', $cutoff);
            })
            ->whereHas('consultations')
            ->whereDoesntHave('consultations', fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->get(['id', 'name', 'contact', 'recall_sms_sent_at']);

        if ($patients->isEmpty()) {
            $this->info("No patients due for recall (threshold: {$months} months).");
            return self::SUCCESS;
        }

        $sms         = new SmsService();
        $wa          = new WhatsAppService();
        $bulkChannel = $s->whatsapp_bulk_channel ?? 'sms';
        ['sms' => $useSms, 'whatsapp' => $useWhatsApp] = AutomaticChannels::pick($bulkChannel, $s, $s->whatsapp_recall_template ?? null);
        $sent        = 0;
        $failed      = 0;

        $this->info(($dryRun ? '[DRY RUN] ' : '') .
            "Found {$patients->count()} patient(s) due for recall (last visit > {$months} months ago).");

        foreach ($patients as $patient) {
            $msg = SmsTemplate::render('patient_recall', [
                '[NAME]'   => $patient->name,
                '[CLINIC]' => $clinic,
            ]);

            if (!$msg) {
                $this->warn("  Skipping {$patient->name} — patient_recall template is empty.");
                continue;
            }

            if ($dryRun) {
                $this->line("  → {$patient->name} ({$patient->contact}): {$msg}");
                continue;
            }

            $anySent = false;

            if ($useSms && !empty($s->sms_enabled) && $s->sms_enabled) {
                $result = $sms->send($patient->contact, $msg, $patient->id, 'patient_recall');
                if ($result['success']) { $anySent = true; }
            }

            if ($useWhatsApp && !empty($s->whatsapp_enabled) && $s->whatsapp_enabled) {
                $templateName = $s->whatsapp_recall_template ?? '';
                if ($templateName) {
                    $result = $wa->sendTemplate(
                        $patient->contact, $templateName, $s->whatsapp_appt_template_lang ?? 'en',
                        [$patient->name, $clinic], $patient->id, 'patient_recall'
                    );
                    if ($result['success']) { $anySent = true; }
                }
            }

            if ($anySent) {
                $patient->update(['recall_sms_sent_at' => now()]);
                $this->info("  Sent to {$patient->name} ({$patient->contact})");
                $sent++;
            } else {
                $this->error("  Failed for {$patient->name}");
                $failed++;
            }
        }

        if (!$dryRun) {
            Log::info("sms:recall-patients — sent: {$sent}, failed: {$failed}, threshold: {$months} months");
            $this->info("Done. Sent: {$sent} | Failed: {$failed}");
        }

        return self::SUCCESS;
    }
}
