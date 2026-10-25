<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Support\Messaging\ClinicLinks;
use App\Support\Messaging\SmsAvailability;
use App\Support\Messaging\SmsSegments;
use Livewire\Component;

/**
 * Communications → Broadcast: one message to a chosen group of patients (Christmas, a new
 * service, a screening day). Part of SMS campaigns; the route requires the feature.
 */
class BroadcastComponent extends Component
{
    use RequiresSuperAdmin;

    public string $message = '';
    /** What [OCCASION] becomes in this send, e.g. "Christmas". */
    public string $occasion = '';
    public string $broadcastFilter = 'all';
    public ?int $broadcastCustomMonths = null;
    public bool $broadcastConfirmStep = false;
    public int $broadcastRecipientCount = 0;
    /** Credits left when counted (null: the clinic's own gateway, no prepaid credits). */
    public ?int $broadcastCredits = null;
    public int $broadcastCreditsNeeded = 0;

    public function mount(): void
    {
        SmsTemplate::ensureDefaults();
        $this->message = (string) SmsTemplate::where('key', 'custom_broadcast')->value('message');
    }

    public function saveMessage(): void
    {
        if (! $this->validMessage()) return;
        SmsTemplate::where('key', 'custom_broadcast')->update(['message' => trim($this->message)]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Broadcast message saved.']);
    }

    public function updatedBroadcastFilter(): void
    {
        $this->broadcastConfirmStep = false;
    }

    public function updatedMessage(): void
    {
        $this->broadcastConfirmStep = false;
    }

    public function prepareBroadcast(): void
    {
        if (! $this->validMessage()) return;
        if (! $this->occasionGiven($this->message)) return;
        if ($this->broadcastFilter === 'custom' && (empty($this->broadcastCustomMonths) || $this->broadcastCustomMonths < 1)) {
            $this->addError('broadcastCustomMonths', 'Please enter a valid number of months.');
            return;
        }

        // A link the message uses must be set for every branch the patients belong to, or it would go out as a gap.
        $missing = [];
        foreach ($this->buildBroadcastQuery()->distinct()->pluck('home_branch_id') as $branchId) {
            $branch = $branchId ? Branch::find($branchId) : null;
            foreach (ClinicLinks::missingIn($this->message, $branch) as $placeholder) {
                $missing[$placeholder][] = $branch?->name ?? 'the clinic';
            }
        }
        if ($missing) {
            $this->addError('message', 'Set these links in Settings → Clinic Links first: ' . collect($missing)
                ->map(fn ($branches, $placeholder) => $placeholder . ' (' . implode(', ', array_unique($branches)) . ')')->implode('; ') . '.');
            return;
        }

        $availability = SmsAvailability::check();
        if (! $availability['available']) {
            $this->addError('message', $availability['reason']);
            return;
        }

        $this->saveMessage();
        $this->broadcastRecipientCount = $this->buildBroadcastQuery()->count();
        $this->broadcastCredits = $availability['credits'];
        $sample = SmsTemplate::fillMessage($this->message, ['[NAME]' => 'Ama Mensah', '[OCCASION]' => trim($this->occasion)]);
        $this->broadcastCreditsNeeded = $this->broadcastRecipientCount * SmsSegments::count($sample);
        $this->broadcastConfirmStep    = true;
    }

    public function cancelBroadcast(): void
    {
        $this->broadcastConfirmStep = false;
    }

    public function sendCustomBroadcast(): void
    {
        $text = (string) SmsTemplate::where('key', 'custom_broadcast')->value('message');
        if (trim($text) === '') {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Broadcast message is empty.']);
            $this->broadcastConfirmStep = false;
            return;
        }
        if (! $this->occasionGiven($text)) {
            $this->broadcastConfirmStep = false;
            return;
        }

        $patients = $this->buildBroadcastQuery()->with('homeBranch')->get(['id', 'name', 'contact', 'home_branch_id']);
        $clinic   = Setting::getSettings()->clinic_name ?? 'the clinic';
        $smsService = new SmsService();
        $sent = $queued = $failed = 0;
        $reasons = [];

        // Hosted clinics hand each message to the queue (one job per patient), so a big list no longer
        // runs out the request's time; offline installs still send them here, one by one.
        foreach ($patients as $patient) {
            // Each patient hears from their own branch: its name, address, phone and links.
            $msg = SmsTemplate::fillMessage($text, ['[NAME]' => $patient->name, '[CLINIC]' => $clinic, '[OCCASION]' => trim($this->occasion)], $patient->homeBranch);
            $result = $smsService->send($patient->contact, $msg, $patient->id, 'custom_broadcast');
            if (!$result['success']) {
                $failed++;
                $reasons[$result['error'] ?? 'Unknown error.'] = true;
            } elseif ($result['queued'] ?? false) {
                $queued++;
            } else {
                $sent++;
            }
        }

        $this->broadcastConfirmStep = false;
        $done = array_filter(["Sent: {$sent}" => $sent, "Queued to send: {$queued}" => $queued, "Failed: {$failed}" => $failed]);
        $this->dispatch('notify', ...[
            'type'    => $failed === 0 ? 'success' : 'warning',
            'message' => 'Broadcast ' . ($queued ? 'on its way' : 'complete') . ' — ' . (implode(', ', array_keys($done)) ?: 'Sent: 0') . '.'
                . ($reasons ? ' Why: ' . implode(' ', array_keys($reasons)) . ' Each one is in SMS Logs.' : '')
                . ($queued ? ' Delivery shows in SMS logs within a few minutes.' : ''),
        ]);
    }

    /** The message is there, short enough, and uses only placeholders a broadcast fills. */
    private function validMessage(): bool
    {
        $this->validate(['message' => 'required|string|max:1000'], ['message.required' => 'Write the message first.']);
        if ($unknown = SmsTemplate::unknownPlaceholders($this->message, $this->placeholders())) {
            $this->addError('message', 'A broadcast cannot fill ' . implode(', ', $unknown) . '. Use only the placeholders listed under the message.');
            return false;
        }

        return true;
    }

    /** [OCCASION] needs the occasion typed in, or patients would get the bracket. */
    private function occasionGiven(string $text): bool
    {
        if (str_contains($text, '[OCCASION]') && trim($this->occasion) === '') {
            $this->addError('occasion', 'Say what the occasion is (for example Christmas); it replaces [OCCASION].');
            return false;
        }

        return true;
    }

    /** @return list<string> */
    private function placeholders(): array
    {
        return array_values(array_unique(array_merge(['[NAME]', '[CLINIC]', '[OCCASION]'], SmsTemplate::CONTEXT_PLACEHOLDERS)));
    }

    /** Patients in the chosen group with a number, leaving out those who asked for no SMS or no marketing. */
    private function buildBroadcastQuery()
    {
        $query = Patient::whereNotNull('contact')->where('contact', '!=', '')
            ->where(fn ($q) => $q->whereNull('sms_opt_out')->orWhere('sms_opt_out', false))
            ->where(fn ($q) => $q->whereNull('marketing_opt_out')->orWhere('marketing_opt_out', false));
        $today = now();

        if ($this->broadcastFilter === 'this_year') {
            $query->whereHas('consultations', fn ($q) => $q->whereYear('created_at', $today->year));
        } elseif ($this->broadcastFilter === 'last_24_months') {
            $query->whereHas('consultations', fn ($q) => $q->where('created_at', '>=', $today->copy()->subMonths(24)));
        } elseif ($this->broadcastFilter === 'custom') {
            $months = max(1, (int) ($this->broadcastCustomMonths ?? 24));
            $query->whereHas('consultations', fn ($q) => $q->where('created_at', '>=', $today->copy()->subMonths($months)));
        }

        return $query;
    }

    public function render()
    {
        return view('livewire.admin.broadcast-component', [
            'placeholders' => $this->placeholders(),
            'sampleName' => 'Ama Mensah',
            'clinicName' => Setting::getSettings()->clinic_name ?? 'the clinic',
        ])->layout('layouts.admin.admin-layout');
    }
}
