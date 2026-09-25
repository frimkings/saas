<?php

namespace App\Console\Commands;

use App\Models\Appointments;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use App\Support\Messaging\AutomaticChannels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminders extends Command
{
    protected $signature   = 'sms:appointment-reminders {--dry-run : Preview without sending}';
    protected $description = 'Send automated reminders for appointments scheduled tomorrow (respects per-appointment channel preference).';

    public function handle(): int
    {
        $s = Setting::getSettings();

        // Window: appointments starting between 23 and 25 hours from now
        $windowStart = now()->addHours(23);
        $windowEnd   = now()->addHours(25);

        $appointments = Appointments::with('patient')
            ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
            ->whereNull('reminder_sent_at')
            ->whereNotIn('status', ['Done', 'Cancelled'])
            ->whereNotIn('reminder_channel', ['none'])
            ->get();

        if ($appointments->isEmpty()) {
            $this->info('No upcoming appointments requiring reminders.');
            return self::SUCCESS;
        }

        $dryRun  = $this->option('dry-run');
        $clinic  = $s->clinic_name ?? 'the clinic';
        $sms     = new SmsService();
        $wa      = new WhatsAppService();
        $sent    = 0;
        $failed  = 0;

        $this->info(($dryRun ? '[DRY RUN] ' : '') .
            "Found {$appointments->count()} appointment(s) needing reminders.");

        foreach ($appointments as $appt) {
            $patient = $appt->patient;
            $channel = $appt->reminder_channel ?? 'sms';

            if (!$patient?->contact) {
                $this->warn("  Skipping {$patient?->name} — no contact number.");
                continue;
            }

            // "WhatsApp" falls back to SMS unless the clinic has set up automatic (Meta) WhatsApp.
            ['sms' => $useSms, 'whatsapp' => $useWhatsApp] = AutomaticChannels::pick($channel, $s, $s->whatsapp_appt_template ?: 'appointment_reminder');

            if ($dryRun) {
                $this->line("  → [{$channel}] {$patient->name} ({$patient->contact}) [{$appt->scheduled_at->format('M d H:i')}]");
                continue;
            }

            $anySent = false;

            // --- SMS path ---
            if ($useSms && !empty($s->sms_enabled) && $s->sms_enabled) {
                $msg = SmsTemplate::render('appointment_auto_reminder', [
                    '[NAME]'   => $patient->name,
                    '[CLINIC]' => $clinic,
                    '[DATE]'   => $appt->scheduled_at->format('M d, Y'),
                    '[TIME]'   => $appt->scheduled_at->format('h:i A'),
                    '[REASON]' => $appt->title,
                ]);

                if ($msg) {
                    $result = $sms->send($patient->contact, $msg, $patient->id, 'appointment_auto_reminder');
                    if ($result['success']) {
                        $anySent = true;
                        $this->info("  SMS → {$patient->name} ({$patient->contact})");
                    } else {
                        $this->error("  SMS failed for {$patient->name}: " . ($result['error'] ?? 'unknown'));
                    }
                }
            }

            // --- WhatsApp path ---
            if ($useWhatsApp && !empty($s->whatsapp_enabled) && $s->whatsapp_enabled) {
                $templateName = $s->whatsapp_appt_template ?? 'appointment_reminder';
                $templateLang = $s->whatsapp_appt_template_lang ?? 'en';

                if ($templateName) {
                    $result = $wa->sendTemplate(
                        $patient->contact,
                        $templateName,
                        $templateLang,
                        [
                            $patient->name,
                            $appt->title,
                            $appt->scheduled_at->format('M d, Y'),
                            $appt->scheduled_at->format('h:i A'),
                            $clinic,
                        ],
                        $patient->id,
                        'appointment_auto_reminder'
                    );

                    if ($result['success']) {
                        $anySent = true;
                        $this->info("  WhatsApp → {$patient->name} ({$patient->contact})");
                    } else {
                        $this->error("  WhatsApp failed for {$patient->name}: " . ($result['error'] ?? 'unknown'));
                    }
                }
            }

            if ($anySent) {
                $appt->update(['reminder_sent_at' => now()]);
                $sent++;
            } else {
                $failed++;
            }
        }

        if (!$dryRun) {
            Log::info("sms:appointment-reminders — sent: {$sent}, failed: {$failed}");
            $this->info("Done. Sent: {$sent} | Failed: {$failed}");
        }

        return self::SUCCESS;
    }
}
