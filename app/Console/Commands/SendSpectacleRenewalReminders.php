<?php

namespace App\Console\Commands;

use App\Support\Messaging\AutomaticChannels;
use App\Models\LensOrder;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSpectacleRenewalReminders extends Command
{
    protected $signature   = 'sms:spectacle-renewal-reminders {--dry-run : Preview without sending}';
    protected $description = 'Queue spectacle renewal reminders for Super Admin approval, or send approved ones.';

    public function handle(): int
    {
        $s = Setting::getSettings();

        if (isset($s->spectacle_renewal_enabled) && !$s->spectacle_renewal_enabled) {
            $this->warn('Spectacle renewal reminders are disabled in Settings.');
            return self::SUCCESS;
        }

        $days   = (int) ($s->spectacle_renewal_reminder_days ?? 30);
        $target = now()->addDays($days)->toDateString();
        $dryRun = $this->option('dry-run');

        // --- Phase 1: queue newly due orders for Super Admin approval ---
        $toQueue = LensOrder::where('status', 'Collected')
            ->whereDate('renewal_date', $target)
            ->whereNull('renewal_reminder_sent_at')
            ->whereNull('renewal_approval_status')
            ->with(['patient', 'refraction.consultation.patient'])
            ->get();

        if ($toQueue->isNotEmpty()) {
            $this->info(($dryRun ? '[DRY RUN] ' : '') .
                "Queuing {$toQueue->count()} renewal(s) for approval (due {$target})");

            foreach ($toQueue as $order) {
                // Clinic orders reach the patient through the refraction; optical
                // orders carry the patient or walk-in customer on the order itself.
                $recipient = $order->renewalRecipient();
                if (!$recipient || !$recipient['phone']) continue;

                if (!$dryRun) {
                    $order->update(['renewal_approval_status' => 'pending']);
                }
                $this->line("  Queued: {$recipient['name']} – renewal {$order->renewal_date->format('d M Y')}");
            }

            if (!$dryRun) {
                NotificationService::sendToRoles(
                    ['Super Admin'],
                    'spectacle_renewal_approval',
                    'Spectacle Renewal Reminders Pending Approval',
                    "{$toQueue->count()} spectacle renewal reminder(s) are awaiting your approval in Admin → Approvals.",
                    'fas fa-redo',
                    'text-info',
                    route('admin.approvals', ['type' => 'spectacle_renewal']),
                    [],
                    null
                );
                Log::info("sms:spectacle-renewal-reminders — queued: {$toQueue->count()}, target_date: {$target}");
            }
        }

        // --- Phase 2: send approved orders ---
        if (isset($s->sms_enabled) && !$s->sms_enabled) {
            $this->warn('SMS notifications are paused — approved reminders will not send until SMS is re-enabled.');
            return self::SUCCESS;
        }

        $approved = LensOrder::where('status', 'Collected')
            ->where('renewal_approval_status', 'approved')
            ->whereNull('renewal_reminder_sent_at')
            ->with(['patient', 'refraction.consultation.patient'])
            ->get();

        if ($approved->isEmpty()) {
            if ($toQueue->isEmpty()) {
                $this->info("No renewals due in {$days} days ({$target}) and no approved reminders pending.");
            }
            return self::SUCCESS;
        }

        $clinic      = $s->clinic_name ?? 'the clinic';
        $sms         = new SmsService();
        $wa          = new WhatsAppService();
        $bulkChannel = $s->whatsapp_bulk_channel ?? 'sms';
        ['sms' => $useSms, 'whatsapp' => $useWhatsApp] = AutomaticChannels::pick($bulkChannel, $s, $s->whatsapp_renewal_template ?? null);
        $sent        = 0;
        $failed      = 0;

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Sending {$approved->count()} approved renewal reminder(s)");

        foreach ($approved as $order) {
            $recipient = $order->renewalRecipient();

            if (!$recipient || !$recipient['phone']) {
                $this->warn("  Skipping order {$order->order_id} — no customer contact.");
                continue;
            }
            $patient = (object) ['name' => $recipient['name'], 'contact' => $recipient['phone'], 'id' => $recipient['patient_id']];

            $msg = SmsTemplate::render('spectacle_renewal', [
                '[NAME]'   => $patient->name,
                '[DATE]'   => \Carbon\Carbon::parse($order->renewal_date)->format('d M Y'),
                '[CLINIC]' => $clinic,
            ]);

            if (!$msg) {
                $this->warn("  Skipping {$patient->name} — spectacle_renewal template is empty.");
                continue;
            }

            if ($dryRun) {
                $this->line("  → {$patient->name} ({$patient->contact}): {$msg}");
                continue;
            }

            $anySent = false;

            if ($useSms && !empty($s->sms_enabled) && $s->sms_enabled) {
                $result = $sms->send($patient->contact, $msg, $patient->id, 'spectacle_renewal');
                if ($result['success']) { $anySent = true; }
            }

            if ($useWhatsApp && !empty($s->whatsapp_enabled) && $s->whatsapp_enabled) {
                $templateName = $s->whatsapp_renewal_template ?? '';
                if ($templateName) {
                    $result = $wa->sendTemplate(
                        $patient->contact, $templateName, $s->whatsapp_appt_template_lang ?? 'en',
                        [$patient->name, \Carbon\Carbon::parse($order->renewal_date)->format('d M Y'), $clinic],
                        $patient->id, 'spectacle_renewal'
                    );
                    if ($result['success']) { $anySent = true; }
                }
            }

            if ($anySent) {
                $order->update(['renewal_reminder_sent_at' => now()]);
                $this->info("  Sent to {$patient->name} ({$patient->contact})");
                $sent++;
            } else {
                $this->error("  Failed for {$patient->name}");
                $failed++;
            }
        }

        if (!$dryRun) {
            Log::info("sms:spectacle-renewal-reminders — sent: {$sent}, failed: {$failed}");
            $this->info("Done. Sent: {$sent} | Failed: {$failed}");
        }

        return self::SUCCESS;
    }
}
