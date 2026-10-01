<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\RequiresSuperAdmin;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Services\LicenseService;
use App\Services\SmsService;
use App\Support\Feature;
use App\Support\Messaging\DefaultSmsTemplates;
use App\Support\Messaging\MessageCatalog;
use App\Support\Messaging\SmsAvailability;
use Livewire\Component;

/**
 * Communications → Messages: every SMS the clinic can send, grouped, each with its wording
 * and (for automatic ones) an on/off switch. All start off. A message's own options (timing,
 * review link, recall months…) are edited with it.
 */
class SmsTemplatesComponent extends Component
{
    use RequiresSuperAdmin;

    /** Messages that come with SMS campaigns (Pro). */
    public const CAMPAIGN_KEYS = ['birthday_wishes', 'patient_recall', 'custom_broadcast', 'appointment_auto_reminder',
        'appointment_missed_auto', 'aftercare_followup', 'clinical_recall', 'feedback_request'];

    public array $templates = [];

    // List controls
    public string $search = '';
    public string $show = 'all'; // all | on | off
    public ?string $editing = null;
    public string $testPhone = '';

    // Birthday filter (persisted to settings)
    public string $birthdayFilter = 'all';
    public ?int $birthdayCustomMonths = null;

    // Inactive-patient recall (persisted to settings; switched on with the message)
    public bool $recallEnabled = false;
    public int $recallMonths = 12;

    // Automatic follow-up timing (persisted to settings; see FollowUpSms)
    public int $aftercareDays = 5;
    public int $recallLeadDays = 7;

    // Balance reminders and feedback requests (persisted to settings)
    public int $balanceFirstDays = 3;
    public int $balanceEveryDays = 7;
    public int $balanceMax = 3;
    public string $reviewLink = '';

    // Spectacle renewal (persisted to settings)
    public int $renewalReminderDays = 30;

    public function mount(): void
    {
        $this->loadTemplates();
        $s = Setting::getSettings();
        $this->birthdayFilter        = $s->birthday_sms_filter        ?? 'all';
        $this->birthdayCustomMonths  = $s->birthday_sms_custom_months ?? null;
        $this->recallEnabled         = (bool) ($s->recall_sms_enabled ?? false);
        $this->recallMonths          = (int)  ($s->recall_months      ?? 12);
        $this->aftercareDays         = (int)  ($s->aftercare_sms_days ?: 5);
        $this->recallLeadDays        = (int)  ($s->clinical_recall_lead_days ?? 7);
        $this->balanceFirstDays      = (int)  ($s->balance_reminder_first_days ?? 3);
        $this->balanceEveryDays      = (int)  ($s->balance_reminder_every_days ?? 7);
        $this->balanceMax            = (int)  ($s->balance_reminder_max ?? 3);
        $this->reviewLink            = (string) ($s->review_link ?? '');
        $this->renewalReminderDays   = (int)  ($s->spectacle_renewal_reminder_days ?? 30);
        $this->testPhone             = (string) (auth()->user()?->phone ?? '');
    }

    private function loadTemplates(): void
    {
        if (!app(\App\Services\ClinicAccessService::class)->access()['read_only']) {
            SmsTemplate::ensureDefaults();
        }

        $this->templates = SmsTemplate::orderBy('id')
            ->where('key', '!=', 'custom_broadcast')
            ->when(! $this->campaigns(), fn ($query) => $query->whereNotIn('key', self::CAMPAIGN_KEYS))
            ->get(['id', 'key', 'label', 'message', 'placeholders', 'is_enabled'])
            ->keyBy('key')
            ->map(fn($t) => [
                'id'           => $t->id,
                'label'        => $t->label,
                'message'      => $t->message,
                'is_enabled'   => (bool) ($t->is_enabled ?? false),
                'placeholders' => array_values(array_unique(array_merge($t->placeholders ?? [], SmsTemplate::CONTEXT_PLACEHOLDERS))),
            ])
            ->toArray();
    }

    public function edit(string $key): void
    {
        $this->editing = $this->editing === $key ? null : $key;
        $this->resetValidation();
    }

    public function save(string $key): void
    {
        $this->validate([
            "templates.{$key}.message" => 'required|string|max:1000',
        ], [
            "templates.{$key}.message.required" => 'Message cannot be empty.',
            "templates.{$key}.message.max"      => 'Message must be 1000 characters or fewer.',
        ]);

        SmsTemplate::where('key', $key)->update([
            'message' => trim($this->templates[$key]['message']),
        ]);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => '"' . $this->templates[$key]['label'] . '" saved.',
        ]);
    }

    public function discardChanges(string $key): void
    {
        $tpl = SmsTemplate::where('key', $key)->first();
        if ($tpl) {
            $this->templates[$key]['message'] = $tpl->message;
        }
    }

    /** Put back the built-in wording (not saved until "Save"). */
    public function resetToDefault(string $key): void
    {
        if ($default = DefaultSmsTemplates::message($key)) {
            $this->templates[$key]['message'] = $default;
        }
    }

    /**
     * Switch an automatic message on or off for this clinic's patients. The text is kept either
     * way. Messages staff send with a button have no switch. Patient recall and spectacle
     * renewal also follow this switch in their scheduled jobs, so there is one switch each.
     */
    public function toggleEnabled(string $key): void
    {
        abort_if(MessageCatalog::isStaffSent($key) || $key === 'custom_broadcast', 422, 'This message is sent by staff and has no switch.');
        if (in_array($key, self::CAMPAIGN_KEYS, true)) $this->requireCampaigns();
        $template = SmsTemplate::where('key', $key)->firstOrFail();
        $template->update(['is_enabled' => ! $template->is_enabled]);
        $this->templates[$key]['is_enabled'] = (bool) $template->is_enabled;
        $this->syncScheduledSwitch($key, (bool) $template->is_enabled);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => '"' . $template->label . '" ' . ($template->is_enabled ? 'will be sent to patients.' : 'is switched off. Patients won\'t get it.'),
        ]);
    }

    /** Switch on the messages almost every clinic wants, in one go. */
    public function turnOnRecommended(): void
    {
        $keys = array_values(array_filter(MessageCatalog::RECOMMENDED, fn ($key) => isset($this->templates[$key])));
        SmsTemplate::whereIn('key', $keys)->update(['is_enabled' => true]);
        foreach ($keys as $key) $this->templates[$key]['is_enabled'] = true;

        $this->dispatch('notify', ...['type' => 'success', 'message' => count($keys) . ' recommended messages switched on.']);
    }

    private function syncScheduledSwitch(string $key, bool $on): void
    {
        $column = ['patient_recall' => 'recall_sms_enabled', 'spectacle_renewal' => 'spectacle_renewal_enabled'][$key] ?? null;
        if (! $column) return;

        Setting::getSettings()->update([$column => $on]);
        if ($key === 'patient_recall') $this->recallEnabled = $on;
    }

    /** Send this message, as it reads now, to a phone number (uses one SMS credit). */
    public function sendTest(string $key): void
    {
        $this->validate(['testPhone' => 'required|string|min:9|max:20'], ['testPhone.required' => 'Enter the phone number to send the test to.']);
        abort_unless(isset($this->templates[$key]), 404);
        $availability = SmsAvailability::check();
        if (! $availability['available']) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => $availability['reason']]);
            return;
        }

        $message = SmsTemplate::fillMessage((string) $this->templates[$key]['message'], $this->sampleValues());
        $result = app(SmsService::class)->sendNow($this->testPhone, $message, null, 'test');
        $this->dispatch('notify', ...[
            'type' => ($result['success'] ?? false) ? 'success' : 'error',
            'message' => ($result['success'] ?? false) ? 'Test sent to ' . $this->testPhone . '.' : 'Test failed: ' . ($result['error'] ?? 'unknown error'),
        ]);
    }

    /** Example values for the preview and the test SMS. */
    public function sampleValues(): array
    {
        return [
            '[NAME]' => 'Ama Mensah', '[DATE]' => now()->addDays(2)->format('M d, Y'), '[TIME]' => '10:30 AM',
            '[REASON]' => 'Eye examination', '[ORDER_ID]' => 'OPT-7KQ2', '[AMOUNT]' => currency() . ' 250.00',
            '[TXN_ID]' => 'TXN-10482', '[PARTNER]' => 'Partner Clinic', '[WEARER]' => 'Kofi Boateng', '[REFERENCE]' => 'REF-21',
            '[COUNT]' => '2', '[JOBS]' => 'OPT-7KQ2, OPT-9LM1', '[OCCASION]' => 'Christmas',
            '[REVIEW_LINK]' => trim($this->reviewLink) ?: 'https://your-review-link',
        ];
    }

    public function saveRecallSettings(): void
    {
        $this->requireCampaigns();
        $this->validate([
            'recallMonths' => 'required|integer|min:1|max:120',
        ], [
            'recallMonths.required' => 'Please enter an inactivity threshold.',
            'recallMonths.min'      => 'Must be at least 1 month.',
            'recallMonths.max'      => 'Cannot exceed 120 months (10 years).',
        ]);

        Setting::getSettings()->update(['recall_months' => $this->recallMonths]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Patient recall settings saved.']);
    }

    /** When the aftercare text and the doctor's recall text go out. */
    public function saveFollowUpTiming(): void
    {
        $this->requireCampaigns();
        $this->validate([
            'aftercareDays'  => 'required|integer|min:1|max:60',
            'recallLeadDays' => 'required|integer|min:0|max:60',
        ], [
            'aftercareDays.min'  => 'Send it at least 1 day after collection.',
            'aftercareDays.max'  => 'Send it within 60 days of collection.',
            'recallLeadDays.max' => 'Send it at most 60 days before the due date.',
        ]);

        Setting::getSettings()->update([
            'aftercare_sms_days'        => $this->aftercareDays,
            'clinical_recall_lead_days' => $this->recallLeadDays,
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Follow-up timing saved.']);
    }

    /** When balance reminders go and how many each unpaid bill gets. Available on every plan. */
    public function saveBalanceReminderSettings(): void
    {
        $this->validate([
            'balanceFirstDays' => 'required|integer|min:0|max:90',
            'balanceEveryDays' => 'required|integer|min:1|max:90',
            'balanceMax'       => 'required|integer|min:1|max:10',
        ], [
            'balanceEveryDays.min' => 'Repeat at most once a day.',
            'balanceMax.max'       => 'Send at most 10 reminders per bill.',
        ]);

        Setting::getSettings()->update([
            'balance_reminder_first_days' => $this->balanceFirstDays,
            'balance_reminder_every_days' => $this->balanceEveryDays,
            'balance_reminder_max'        => $this->balanceMax,
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Balance reminder schedule saved.']);
    }

    /** The link patients are sent for reviews, e.g. the clinic's Google review page. */
    public function saveReviewLink(): void
    {
        $this->requireCampaigns();
        $this->reviewLink = trim($this->reviewLink);
        $this->validate(['reviewLink' => 'nullable|url:http,https|max:500'], ['reviewLink.url' => 'Enter a full web address starting with https://']);

        Setting::getSettings()->update(['review_link' => $this->reviewLink ?: null]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => $this->reviewLink ? 'Review link saved.' : 'Review link removed.']);
    }

    /** How many days ahead of the renewal date the spectacle renewal reminder goes. */
    public function saveRenewalSettings(): void
    {
        $this->validate(['renewalReminderDays' => 'required|integer|min:1|max:90']);

        Setting::getSettings()->update(['spectacle_renewal_reminder_days' => $this->renewalReminderDays]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Spectacle renewal timing saved.']);
    }

    public function saveBirthdaySettings(): void
    {
        $this->requireCampaigns();
        $this->validate([
            'birthdayFilter'       => 'required|in:all,this_year,last_24_months,custom',
            'birthdayCustomMonths' => 'nullable|integer|min:1|max:120',
        ], [
            'birthdayFilter.in'            => 'Please select a valid filter option.',
            'birthdayCustomMonths.integer' => 'Months must be a whole number.',
            'birthdayCustomMonths.min'     => 'Must be at least 1 month.',
            'birthdayCustomMonths.max'     => 'Cannot exceed 120 months (10 years).',
        ]);

        Setting::getSettings()->update([
            'birthday_sms_filter'        => $this->birthdayFilter,
            'birthday_sms_custom_months' => $this->birthdayFilter === 'custom' ? $this->birthdayCustomMonths : null,
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Birthday SMS filter saved.']);
    }

    public function campaigns(): bool
    {
        return LicenseService::has(Feature::SMS_CAMPAIGNS);
    }

    private function requireCampaigns(): void
    {
        abort_unless($this->campaigns(), 403, 'SMS campaigns require a Pro license.');
    }

    /** Messages grouped for the page, after search and the on/off filter. */
    private function groupedMessages(): array
    {
        $search = mb_strtolower(trim($this->search));
        $groups = [];
        foreach (MessageCatalog::MESSAGES as $key => [$group, $icon, $colour, $when, $kind, $pro]) {
            $template = $this->templates[$key] ?? null;
            if (! $template) continue;
            $staff = $kind === 'staff';
            if ($this->show === 'on' && ($staff || ! $template['is_enabled'])) continue;
            if ($this->show === 'off' && ($staff || $template['is_enabled'])) continue;
            if ($search !== '' && ! str_contains(mb_strtolower($template['label'] . ' ' . $when . ' ' . $template['message']), $search)) continue;

            $groups[$group][$key] = $template + compact('icon', 'colour', 'when', 'staff', 'pro');
        }

        return $groups;
    }

    public function render()
    {
        $automatic = collect($this->templates)->reject(fn ($t, $key) => MessageCatalog::isStaffSent($key));

        return view('livewire.admin.sms-templates-component', [
            'campaigns' => $this->campaigns(),
            'groups' => $this->groupedMessages(),
            'onCount' => $automatic->where('is_enabled', true)->count(),
            'automaticCount' => $automatic->count(),
            'availability' => SmsAvailability::check(),
            'samples' => $this->sampleValues(),
        ])->layout('layouts.admin.admin-layout');
    }
}
