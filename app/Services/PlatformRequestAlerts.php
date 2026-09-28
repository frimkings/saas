<?php

namespace App\Services;

use App\Mail\OwnerNoticeMail;
use App\Models\Clinic;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\SubscriptionChangeRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Emails to the platform's requests inbox (Platform → Support) when a clinic asks for
 * something only the platform can do: change plan, buy SMS, or use its own sender ID.
 * Each request is emailed once as it arrives; every morning at 8:00 one reminder lists those
 * still waiting after a day.
 */
class PlatformRequestAlerts
{
    public const INBOX_SETTING = 'requests_email';
    public const REMIND_AFTER_HOURS = 24;

    public function __construct(private readonly OwnerMailer $mailer) {}

    /** A separate requests inbox if one is set, otherwise the platform's support email. */
    public static function inbox(): ?string
    {
        return PlatformSetting::get(self::INBOX_SETTING) ?: PlatformSetting::support()['email'];
    }

    public function planChangeRequested(SubscriptionChangeRequest $request): void
    {
        $request->loadMissing(['clinic', 'requestedPlan', 'currentSubscription.plan']);
        if (! $request->clinic) return;
        $by = User::find($request->requested_by);

        $this->send($request->clinic, 'platform_plan_request', 'plan_request:' . $request->id,
            "{$request->clinic->name} wants to change plan",
            ($by?->name ?? 'The clinic') . " asked to move {$request->clinic->name} to {$request->requestedPlan?->name}. Approve or reject it in Billing → Approvals.",
            array_filter([
                'Clinic' => $request->clinic->name,
                'Current plan' => $request->currentSubscription?->plan?->name,
                'Requested plan' => $request->requestedPlan?->name,
                'Billing' => $request->billing_interval ? ucfirst($request->billing_interval) : null,
                'Message' => $request->message,
                'Asked by' => $by ? "{$by->name} ({$by->email})" : null,
            ]),
            'Review request', route('platform.dashboard', ['tab' => 'billing']));
    }

    public function smsBundleOrdered(PlatformInvoice $invoice): void
    {
        $invoice->loadMissing('clinic');
        if (! $invoice->clinic) return;

        $this->send($invoice->clinic, 'platform_sms_bundle', 'sms_bundle:' . $invoice->id,
            "{$invoice->clinic->name} ordered SMS credits",
            "{$invoice->clinic->name} ordered " . number_format((int) $invoice->sms_credits) . ' SMS credits. The credits are added when the invoice is marked paid.',
            [
                'Clinic' => $invoice->clinic->name,
                'Credits' => number_format((int) $invoice->sms_credits),
                'Invoice' => $invoice->number,
                'Amount' => $invoice->currency . ' ' . number_format((float) $invoice->total, 2),
                'Due' => $invoice->due_date?->format('j M Y'),
            ],
            'Open invoices', route('platform.dashboard', ['tab' => 'billing']));
    }

    public function senderIdRequested(Clinic $clinic, string $senderId): void
    {
        $this->send($clinic, 'platform_sender_id', 'sender_id:' . $clinic->id . ':' . $senderId . ':' . now()->format('YmdHis'),
            "{$clinic->name} asked for sender ID {$senderId}",
            "{$clinic->name} wants its SMS to come from \"{$senderId}\". Register it with the SMS provider, then approve or reject it. Until then their messages use the platform sender.",
            ['Clinic' => $clinic->name, 'Sender ID' => $senderId, 'Asked by' => auth()->user() ? auth()->user()->name . ' (' . auth()->user()->email . ')' : null],
            'Review sender IDs', route('platform.sms'));
    }

    /** One morning email listing every request still waiting after a day. Nothing is sent if none are. */
    public function remindWaiting(): ?string
    {
        $cutoff = now()->subHours(self::REMIND_AFTER_HOURS);
        $waiting = $this->waiting($cutoff);
        if ($waiting->isEmpty()) return null;

        $count = $waiting->count();
        $details = [];
        foreach ($waiting->take(15) as $item) {
            $details[$item['what'] . ' · ' . $item['clinic']] = 'waiting ' . $item['since']->diffForHumans(null, true);
        }
        if ($count > 15) $details['And'] = ($count - 15) . ' more';

        return $this->mailer->sendToPlatform(null, self::inbox(), 'platform_reminder', 'reminder:' . now()->toDateString(),
            ($count === 1 ? '1 clinic request is' : "{$count} clinic requests are") . ' still waiting',
            fn () => new OwnerNoticeMail(config('mail.from.name') . ' · Platform', ($count === 1 ? '1 request is' : "{$count} requests are") . ' still waiting',
                'These clinic requests have waited more than a day for an answer.', $details,
                'Open the platform dashboard', route('platform.dashboard'), null, $this->footer()));
    }

    /** @return Collection<int, array{what: string, clinic: string, since: \Illuminate\Support\Carbon}> */
    private function waiting($cutoff): Collection
    {
        $clinics = fn ($ids) => Clinic::whereIn('id', $ids)->pluck('name', 'id');

        $plans = SubscriptionChangeRequest::where('status', 'pending')->where('created_at', '<', $cutoff)->get(['clinic_id', 'created_at']);
        $bundles = PlatformInvoice::where('source', 'sms_bundle')->whereIn('status', ['unpaid', 'partial'])->where('created_at', '<', $cutoff)->get(['clinic_id', 'created_at']);
        $senders = Setting::withoutGlobalScopes()->where('sms_sender_id_status', 'pending')->where('sms_sender_id_requested_at', '<', $cutoff)
            ->get(['clinic_id', 'sms_sender_id_requested', 'sms_sender_id_requested_at']);
        $names = $clinics($plans->pluck('clinic_id')->merge($bundles->pluck('clinic_id'))->merge($senders->pluck('clinic_id'))->unique());

        return collect()
            ->merge($plans->map(fn ($r) => ['what' => 'Plan change', 'clinic' => $names[$r->clinic_id] ?? 'Clinic', 'since' => $r->created_at]))
            ->merge($bundles->map(fn ($r) => ['what' => 'SMS credits', 'clinic' => $names[$r->clinic_id] ?? 'Clinic', 'since' => $r->created_at]))
            ->merge($senders->map(fn ($r) => ['what' => "Sender ID {$r->sms_sender_id_requested}", 'clinic' => $names[$r->clinic_id] ?? 'Clinic',
                'since' => \Illuminate\Support\Carbon::parse($r->sms_sender_id_requested_at)]))
            ->sortBy('since')->values();
    }

    private function send(Clinic $clinic, string $kind, string $key, string $heading, string $intro, array $details, string $button, string $url): void
    {
        $this->mailer->sendToPlatform($clinic, self::inbox(), $kind, $key, $heading,
            fn () => new OwnerNoticeMail(config('mail.from.name') . ' · Platform', $heading, $intro, array_filter($details), $button, $url, null, $this->footer()));
    }

    private function footer(): string
    {
        return 'Sent to the platform requests inbox. Change it under Platform → Support.';
    }
}
