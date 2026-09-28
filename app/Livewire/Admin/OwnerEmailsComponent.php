<?php

namespace App\Livewire\Admin;

use App\Models\OwnerEmail;
use App\Services\OwnerMailer;
use App\Services\OwnerSummaryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Settings > Owner Emails: where the clinic's emails go, which summaries its plan includes,
 * and what has been sent. Nothing to configure here: the owner email is the billing email,
 * and the platform decides the summaries per plan.
 */
class OwnerEmailsComponent extends Component
{
    /** Shown on the optical Settings page (optical styling) rather than the clinic Settings tabs. */
    #[\Livewire\Attributes\Locked]
    public bool $optical = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
    }

    /** Send yesterday's daily summary now, to check the owner email works. */
    public function sendTest(OwnerSummaryService $summaries): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
        $clinic = app(TenantContext::class)->requireClinic();
        if (! OwnerMailer::ownerEmail($clinic)) {
            $this->dispatch('notify', type: 'error', message: 'Add an owner email under Subscription → Billing profile first.');
            return;
        }
        if (RateLimiter::tooManyAttempts('owner-summary-test:' . $clinic->id, 3)) {
            $this->dispatch('notify', type: 'warning', message: 'Test emails are limited to 3 an hour. Try again later.');
            return;
        }
        RateLimiter::hit('owner-summary-test:' . $clinic->id, 3600);

        $local = Carbon::now($clinic->default_timezone ?: config('app.timezone'));
        [$from, $to] = OwnerSummaryService::period('daily', $local);
        $status = $summaries->send($clinic, 'daily', $from, $to, 'test:' . now()->format('YmdHisv'));
        $this->dispatch('notify', ...$status === OwnerEmail::SENT
            ? ['type' => 'success', 'message' => 'Test summary sent to ' . OwnerMailer::ownerEmail($clinic) . '.']
            : ['type' => 'error', 'message' => 'The email could not be sent. See the list below for the reason.']);
    }

    public function render(OwnerSummaryService $summaries)
    {
        $clinic = app(TenantContext::class)->requireClinic();

        return view($this->optical ? 'livewire.optical.owner-emails-panel' : 'livewire.admin.owner-emails-component', [
            'ownerEmail' => OwnerMailer::ownerEmail($clinic),
            'timezone' => $clinic->default_timezone ?: config('app.timezone'),
            'included' => collect(OwnerSummaryService::PERIODS + ['alerts' => \App\Support\Feature::MORNING_ALERTS])->map(fn ($feature) => $summaries->includes($clinic, $feature))->all(),
            // Emails about this clinic sent to the platform's own inbox aren't the clinic's to see.
            'emails' => OwnerEmail::where('clinic_id', $clinic->id)->where('kind', 'not like', 'platform\_%')->latest('id')->limit(25)->get(),
        ]);
    }
}
