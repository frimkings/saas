<?php

namespace App\Livewire\Platform;

use App\Mail\PlatformAnnouncementMail;
use App\Models\Clinic;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementRecipient;
use App\Models\SubscriptionPlan;
use App\Services\Platform\Announcements;
use App\Services\PlatformAuditService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Platform → Announcements: write a message, pick the clinics, preview, test, send, and follow
 * delivery per address. Drafts can be edited; a sent announcement is fixed.
 */
class AnnouncementsComponent extends Component
{
    use WithPagination;

    public string $screen = 'list'; // list | edit | show
    public ?int $announcementId = null;

    public array $form = [];
    public array $audience = [];
    public string $clinicSearch = '';
    public string $testEmail = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $this->testEmail = (string) auth()->user()->email;
        $this->resetForm();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->announcementId = null;
        $this->screen = 'edit';
    }

    public function open(int $id): void
    {
        $announcement = PlatformAnnouncement::findOrFail($id);
        $this->announcementId = $announcement->id;
        if ($announcement->isDraft()) {
            $this->form = $announcement->only(['subject', 'heading', 'body', 'button_label', 'button_url', 'send_email', 'show_banner'])
                + ['banner_until' => $announcement->banner_until?->toDateString() ?? ''];
            $this->audience = array_merge($this->blankAudience(), $announcement->audience ?? []);
            $this->screen = 'edit';
        } else {
            $this->screen = 'show';
        }
        $this->resetValidation();
    }

    public function backToList(): void
    {
        $this->screen = 'list';
        $this->announcementId = null;
    }

    public function saveDraft(): ?PlatformAnnouncement
    {
        $data = $this->validate([
            'form.subject'      => 'required|string|max:150',
            'form.heading'      => 'required|string|max:150',
            'form.body'         => 'required|string|max:10000',
            'form.button_label' => 'nullable|required_with:form.button_url|string|max:60',
            'form.button_url'   => 'nullable|required_with:form.button_label|url|max:500',
            'form.send_email'   => 'boolean',
            'form.show_banner'  => 'boolean',
            'form.banner_until' => 'nullable|date|after_or_equal:today',
        ], [], [
            'form.subject' => 'subject', 'form.heading' => 'heading', 'form.body' => 'message',
            'form.button_label' => 'button text', 'form.button_url' => 'button link', 'form.banner_until' => 'banner end date',
        ])['form'];

        if (!$data['send_email'] && !$data['show_banner']) {
            $this->addError('form.send_email', 'Choose email, banner, or both.');
            return null;
        }

        $values = $data + ['audience' => $this->cleanAudience()];
        $values['banner_until'] = $values['banner_until'] ?: null;
        $values['button_label'] = $values['button_label'] ?: null;
        $values['button_url'] = $values['button_url'] ?: null;

        $announcement = $this->announcementId ? PlatformAnnouncement::findOrFail($this->announcementId) : null;
        if ($announcement && !$announcement->isDraft()) {
            $this->addError('form.subject', 'This announcement has been sent and can no longer be changed.');
            return null;
        }

        $announcement
            ? $announcement->update($values)
            : $announcement = PlatformAnnouncement::create($values + ['created_by' => auth()->id(), 'status' => PlatformAnnouncement::DRAFT]);
        $this->announcementId = $announcement->id;
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Draft saved.']);

        return $announcement;
    }

    public function sendTest(Announcements $announcements): void
    {
        $this->validate(['testEmail' => 'required|email'], [], ['testEmail' => 'test address']);
        if (!$announcement = $this->saveDraft()) {
            return;
        }
        try {
            $announcements->sendTest($announcement, $this->testEmail);
            $this->dispatch('notify', ...['type' => 'success', 'message' => "Test sent to {$this->testEmail}."]);
        } catch (\Throwable $e) {
            $this->addError('testEmail', 'The test could not be sent: ' . $e->getMessage());
        }
    }

    public function send(Announcements $announcements, PlatformAuditService $audit): void
    {
        if (!$announcement = $this->saveDraft()) {
            return;
        }
        $preview = $announcements->preview($announcement->audience ?? []);
        if ($preview['clinics'] === 0) {
            $this->addError('audience', 'No clinic matches this audience.');
            return;
        }

        $announcements->send($announcement);
        $audit->record('PLATFORM_ANNOUNCEMENT_SENT', null, [], [
            'announcement_id' => $announcement->id, 'subject' => $announcement->subject,
            'clinics' => $preview['clinics'], 'email' => $announcement->send_email, 'banner' => $announcement->show_banner,
        ]);

        $this->screen = 'show';
        $this->dispatch('notify', ...['type' => 'success', 'message' => "Sending to {$preview['clinics']} clinic(s)."]);
    }

    public function retryFailed(Announcements $announcements): void
    {
        $count = $announcements->retryFailed(PlatformAnnouncement::findOrFail($this->announcementId));
        $this->dispatch('notify', ...['type' => $count ? 'success' : 'info', 'message' => $count ? "Retrying {$count} email(s)." : 'Nothing to retry.']);
    }

    public function deleteDraft(): void
    {
        $announcement = PlatformAnnouncement::findOrFail($this->announcementId);
        if ($announcement->isDraft()) {
            $announcement->delete();
        }
        $this->backToList();
    }

    /** End the banner now (emails already sent are unaffected). */
    public function stopBanner(): void
    {
        PlatformAnnouncement::whereKey($this->announcementId)->update(['banner_until' => today()->subDay()->toDateString()]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Banner taken down.']);
    }

    private function resetForm(): void
    {
        $this->form = [
            'subject' => '', 'heading' => '', 'body' => '',
            'button_label' => 'Open the app', 'button_url' => url('/login'),
            'send_email' => true, 'show_banner' => true, 'banner_until' => '',
        ];
        $this->audience = $this->blankAudience();
        $this->clinicSearch = '';
        $this->resetValidation();
    }

    private function blankAudience(): array
    {
        return ['statuses' => [], 'plan_ids' => [], 'modes' => [], 'clinic_ids' => []];
    }

    private function cleanAudience(): array
    {
        return [
            'statuses'   => array_values(array_intersect($this->audience['statuses'] ?? [], array_keys(Announcements::SUBSCRIPTION_STATUSES))),
            'plan_ids'   => array_values(array_map('intval', $this->audience['plan_ids'] ?? [])),
            'modes'      => array_values(array_intersect($this->audience['modes'] ?? [], ['hosted', 'offline'])),
            'clinic_ids' => array_values(array_map('intval', $this->audience['clinic_ids'] ?? [])),
        ];
    }

    public function render(Announcements $announcements)
    {
        $data = ['screen' => $this->screen];

        if ($this->screen === 'list') {
            $data['announcements'] = PlatformAnnouncement::withCount([
                    'recipients as sent_count'    => fn ($q) => $q->where('status', PlatformAnnouncementRecipient::SENT),
                    'recipients as failed_count'  => fn ($q) => $q->where('status', PlatformAnnouncementRecipient::FAILED),
                    'recipients as queued_count'  => fn ($q) => $q->where('status', PlatformAnnouncementRecipient::QUEUED),
                ])
                ->selectRaw('(SELECT COUNT(DISTINCT clinic_id) FROM platform_announcement_recipients r WHERE r.platform_announcement_id = platform_announcements.id) AS clinic_count')
                ->latest()
                ->paginate(15);
        } elseif ($this->screen === 'edit') {
            $preview = $announcements->preview($this->cleanAudience());
            $sample = new PlatformAnnouncement($this->form);
            $data += [
                'preview'  => $preview,
                'plans'    => SubscriptionPlan::orderBy('name')->pluck('name', 'id'),
                'clinics'  => Clinic::where('status', 'active')
                    ->when(trim($this->clinicSearch) !== '', fn ($q) => $q->where('name', 'like', '%' . trim($this->clinicSearch) . '%'))
                    ->orderBy('name')->limit(50)->get(['id', 'name', 'deployment_mode']),
                'emailHtml' => trim($this->form['heading'] . $this->form['body']) !== ''
                    ? (new PlatformAnnouncementMail($sample, $preview['names'][0] ?? 'Sample Clinic'))->render()
                    : null,
            ];
        } else {
            $announcement = PlatformAnnouncement::findOrFail($this->announcementId);
            $recipients = $announcement->recipients()->with('clinic:id,name')->orderBy('clinic_id')->orderBy('id')->get();
            $data += [
                'announcement' => $announcement,
                'recipients'   => $recipients,
                'counts'       => $recipients->countBy('status'),
            ];
        }

        return view('livewire.platform.announcements-component', $data)->layout('layouts.platform');
    }
}
