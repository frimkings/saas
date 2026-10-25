<?php

namespace App\Livewire\Admin;

use App\Support\Messaging\AutomaticChannels;
use App\Models\AuditTrail;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;

class PatientRecallComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    public string $activeTab = 'due';
    public string $search    = '';
    public int    $perPage   = 20;

    protected $queryString = [
        'activeTab' => ['except' => 'due', 'as' => 'tab'],
        'search'    => ['except' => ''],
    ];

    public function updatingActiveTab(): void { $this->resetPage(); }
    public function updatingSearch(): void     { $this->resetPage(); }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function sendRecall(int $patientId): void
    {
        $s = Setting::getSettings();

        if (empty($s->sms_enabled) || !$s->sms_enabled) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'SMS is disabled in settings.']);
            return;
        }

        $patient     = Patient::findOrFail($patientId);
        $clinic      = $s->clinic_name ?? 'the clinic';
        $bulkChannel = $s->whatsapp_bulk_channel ?? 'sms';
        ['sms' => $useSms, 'whatsapp' => $useWhatsApp] = AutomaticChannels::pick($bulkChannel, $s, $s->whatsapp_recall_template ?? null);

        $msg = $this->recallMessage($patient, $clinic);

        if (!$msg) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => $this->whyNoMessage($patient)]);
            return;
        }

        $sent = false;

        if ($useSms) {
            $result = (new SmsService())->send($patient->contact, $msg, $patient->id, 'patient_recall');
            if ($result['success']) { $sent = true; }
        }

        if ($useWhatsApp && !empty($s->whatsapp_enabled) && $s->whatsapp_enabled) {
            $tpl = $s->whatsapp_recall_template ?? '';
            if ($tpl) {
                $result = (new WhatsAppService())->sendTemplate(
                    $patient->contact, $tpl, $s->whatsapp_appt_template_lang ?? 'en',
                    [$patient->name, $clinic], $patient->id, 'patient_recall'
                );
                if ($result['success']) { $sent = true; }
            }
        }

        if ($sent) {
            $patient->update(['recall_sms_sent_at' => now()]);
            AuditTrail::record('recall.sent', "Manual recall sent to {$patient->name}", $patient, [], [], $patient->id);
            $this->dispatch('notify', ...['type' => 'success', 'message' => "Recall sent to {$patient->name}."]);
        } else {
            $this->dispatch('notify', ...['type' => 'error', 'message' => "Failed to send recall to {$patient->name}. Check SMS settings."]);
        }
    }

    public function sendBulkRecall(): void
    {
        $s = Setting::getSettings();

        if (empty($s->sms_enabled) || !$s->sms_enabled) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'SMS is disabled in settings.']);
            return;
        }

        $patients    = $this->dueQuery()->with('homeBranch')->get(['id', 'name', 'contact', 'home_branch_id']);
        $clinic      = $s->clinic_name ?? 'the clinic';
        $bulkChannel = $s->whatsapp_bulk_channel ?? 'sms';
        ['sms' => $useSms, 'whatsapp' => $useWhatsApp] = AutomaticChannels::pick($bulkChannel, $s, $s->whatsapp_recall_template ?? null);
        $smsService  = new SmsService();
        $waService   = new WhatsAppService();
        $sent = $failed = 0;
        $reason = null;

        foreach ($patients as $patient) {
            $msg = $this->recallMessage($patient, $clinic);
            if (!$msg) { $failed++; $reason ??= $this->whyNoMessage($patient); continue; }

            $anySent = false;

            if ($useSms) {
                $result = $smsService->send($patient->contact, $msg, $patient->id, 'patient_recall');
                if ($result['success']) { $anySent = true; }
            }

            if ($useWhatsApp && !empty($s->whatsapp_enabled) && $s->whatsapp_enabled) {
                $tpl = $s->whatsapp_recall_template ?? '';
                if ($tpl) {
                    $result = $waService->sendTemplate(
                        $patient->contact, $tpl, $s->whatsapp_appt_template_lang ?? 'en',
                        [$patient->name, $clinic], $patient->id, 'patient_recall'
                    );
                    if ($result['success']) { $anySent = true; }
                }
            }

            if ($anySent) {
                $patient->update(['recall_sms_sent_at' => now()]);
                $sent++;
            } else {
                $failed++;
            }
        }

        Log::info("PatientRecall bulk — sent: {$sent}, failed: {$failed}");
        AuditTrail::record('recall.bulk_sent', "Bulk recall: {$sent} sent, {$failed} failed");
        $this->dispatch('notify', ...[
            'type'    => $failed > 0 ? 'warning' : 'success',
            'message' => "Bulk recall done — {$sent} sent, {$failed} failed." . ($reason ? ' ' . $reason : ''),
        ]);
    }

    public function resetRecall(int $patientId): void
    {
        $patient = Patient::findOrFail($patientId);
        $patient->update(['recall_sms_sent_at' => null]);
        AuditTrail::record('recall.reset', "Recall cycle reset for {$patient->name}", $patient, [], [], $patient->id);
        $this->dispatch('notify', ...['type' => 'info', 'message' => "{$patient->name} moved back to due list."]);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        $s      = Setting::getSettings();
        $months = max(1, (int) ($s->recall_months ?? 12));

        $patients = match($this->activeTab) {
            'sent'     => $this->sentQuery($months),
            'returned' => $this->returnedQuery($months),
            default    => $this->dueQuery($months),
        };

        if ($this->search) {
            $search = $this->search;
            $patients = $patients->where(fn ($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('pxnumber', 'like', "%{$search}%")
            );
        }

        $patientsPaginated = $patients->paginate($this->perPage);

        $dueCount      = $this->dueQuery($months)->count();
        $sentCount     = $this->sentQuery($months)->count();
        $returnedCount = $this->returnedQuery($months)->count();

        return view('livewire.admin.patient-recall-component', compact(
            'patientsPaginated', 'months', 'dueCount', 'sentCount', 'returnedCount', 's'
        ))->layout('layouts.admin.admin-layout');
    }

    /**
     * The recall wording for one patient. Sending from this page works whether or not the
     * automatic recall is switched on; the patient's home branch fills the branch details.
     */
    private function recallMessage(Patient $patient, string $clinic): string
    {
        return SmsTemplate::render('patient_recall', ['[NAME]' => $patient->name, '[CLINIC]' => $clinic], $patient->homeBranch, evenIfOff: true);
    }

    private function whyNoMessage(Patient $patient): string
    {
        $message = (string) (SmsTemplate::where('key', 'patient_recall')->value('message') ?? '');
        $missing = \App\Support\Messaging\ClinicLinks::missingIn($message, $patient->homeBranch);

        return $missing
            ? 'The recall message uses ' . implode(', ', $missing) . ', which is not set. Add it in Settings → Clinic Links.'
            : 'The recall message is empty. Write it in Communications → Messages.';
    }

    // ── Private query builders ────────────────────────────────────────────────

    private function dueQuery(int $months = 12)
    {
        $cutoff = now()->subMonths($months);
        return Patient::whereNotNull('contact')
            ->where('contact', '!=', '')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('recall_sms_sent_at')
                  ->orWhere('recall_sms_sent_at', '<', $cutoff);
            })
            ->whereHas('consultations')
            ->whereDoesntHave('consultations', fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->withMax('consultations', 'created_at')
            ->orderBy('consultations_max_created_at');
    }

    private function sentQuery(int $months = 12)
    {
        $cutoff = now()->subMonths($months);
        return Patient::whereNotNull('recall_sms_sent_at')
            ->where('recall_sms_sent_at', '>=', $cutoff)
            ->withMax('consultations', 'created_at')
            ->orderByDesc('recall_sms_sent_at');
    }

    private function returnedQuery(int $months = 12)
    {
        $cutoff = now()->subMonths($months);
        return Patient::whereNotNull('recall_sms_sent_at')
            ->whereHas('consultations', fn ($q) =>
                $q->whereColumn('created_at', '>', 'patients.recall_sms_sent_at')
            )
            ->withMax('consultations', 'created_at')
            ->orderByDesc('recall_sms_sent_at');
    }
}
