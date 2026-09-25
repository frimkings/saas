<?php

namespace App\Services;

use App\Models\LensOrder;
use App\Models\OpticalPartnerClinic;
use App\Models\OpticalSetting;
use App\Models\SmsTemplate;
use App\Support\Messaging\SmsAvailability;
use App\Support\Messaging\WhatsAppLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * "Glasses ready" and "still waiting for collection" messages for optical orders.
 *
 * Own customers are messaged directly. Jobs sent in by a partner clinic are reported to
 * the partner only (its notification phone, by its chosen channel); pickup reminders to a
 * partner list all of its waiting jobs in one message. SMS goes through SmsService
 * (credits, opt-outs, logs); WhatsApp is a wa.me link staff send from their own phone.
 */
class OpticalCollectionNotifier
{
    public const READY = 'spectacles_ready';
    public const REMINDER = 'spectacles_reminder';
    private const WAITING = ['Ready for Collection', 'Ready'];

    /**
     * @return array{name: string, phone: ?string, patient_id: ?int, partner: ?OpticalPartnerClinic, notify_via: string}|null
     */
    public function recipient(LensOrder $order): ?array
    {
        if ($order->isPartnerJob()) {
            $partner = $order->partnerClinic;
            return $partner ? ['name' => $partner->name, 'phone' => $partner->messagingPhone(), 'patient_id' => null,
                'partner' => $partner, 'notify_via' => $partner->notify_via ?: 'sms'] : null;
        }
        $wearer = $order->renewalRecipient();
        return $wearer ? $wearer + ['partner' => null, 'notify_via' => 'sms'] : null;
    }

    public function message(LensOrder $order, string $kind): string
    {
        $this->assertKind($kind);
        if ($order->isPartnerJob() && $order->partnerClinic) {
            return $kind === self::READY
                ? SmsTemplate::render('partner_job_ready', [
                    '[PARTNER]' => $order->partnerClinic->name,
                    '[ORDER_ID]' => $order->order_id,
                    '[WEARER]' => $order->customer_name ?: 'your patient',
                    '[REFERENCE]' => $order->partnerReference() ?: $order->order_id,
                ])
                : $this->partnerDigestMessage($order->partnerClinic);
        }
        return SmsTemplate::render($kind, [
            '[NAME]' => $this->recipient($order)['name'] ?? 'Customer',
            '[ORDER_ID]' => $order->order_id,
        ]);
    }

    /** Ready jobs from this partner still waiting for collection. */
    public function waitingForPartner(OpticalPartnerClinic $partner): Collection
    {
        return LensOrder::whereIn('status', self::WAITING)->where('order_source', 'partner')
            ->where('partner_clinic_id', $partner->id)->orderBy('ready_at')->get();
    }

    public function partnerDigestMessage(OpticalPartnerClinic $partner, ?Collection $orders = null): string
    {
        $orders ??= $this->waitingForPartner($partner);
        return SmsTemplate::render('partner_jobs_awaiting', [
            '[PARTNER]' => $partner->name,
            '[COUNT]' => (string) $orders->count(),
            '[JOBS]' => $orders->map(fn (LensOrder $order) => $order->order_id.($order->customer_name ? ' ('.$order->customer_name.')' : ''))->implode(', '),
        ]);
    }

    public function whatsAppLink(LensOrder $order, string $kind): ?string
    {
        $recipient = $this->recipient($order);
        if (($recipient['notify_via'] ?? 'sms') === 'none') return null;
        return WhatsAppLink::to($recipient['phone'] ?? null, $this->message($order, $kind));
    }

    public function partnerWhatsAppLink(OpticalPartnerClinic $partner): ?string
    {
        if ($partner->notify_via === 'none') return null;
        return WhatsAppLink::to($partner->messagingPhone(), $this->partnerDigestMessage($partner));
    }

    /** Whether the SMS button should be offered for this order (SMS availability is checked separately). */
    public function smsAllowed(LensOrder $order): bool
    {
        $recipient = $this->recipient($order);
        return ($recipient['phone'] ?? null) && ($recipient['notify_via'] ?? 'sms') === 'sms';
    }

    /** @return array{success: bool, error: ?string} */
    public function sendSms(LensOrder $order, string $kind, ?int $stepsDone = null): array
    {
        $this->assertKind($kind);
        $recipient = $this->recipient($order);
        if (! ($recipient['phone'] ?? null)) return ['success' => false, 'error' => 'This order has no phone number to text.'];
        if ($recipient['notify_via'] === 'none') return ['success' => false, 'error' => "{$recipient['name']} asked not to be notified."];
        if ($recipient['notify_via'] === 'whatsapp') return ['success' => false, 'error' => "{$recipient['name']} prefers WhatsApp. Send it from Awaiting Collection."];
        if ($recipient['partner'] && $kind === self::REMINDER) return $this->sendPartnerDigest($recipient['partner']);

        $result = $this->deliver($recipient['phone'], $this->message($order, $kind), $recipient['patient_id'], $kind);
        if ($result['success']) $this->recordSent($order, $kind, $stepsDone);
        return $result;
    }

    /**
     * One reminder listing every waiting job from this partner.
     *
     * @param  array<int, int>  $stepsDone  order id => schedule steps now covered (automatic reminders)
     * @return array{success: bool, error: ?string}
     */
    public function sendPartnerDigest(OpticalPartnerClinic $partner, array $stepsDone = []): array
    {
        $orders = $this->waitingForPartner($partner);
        if ($orders->isEmpty()) return ['success' => false, 'error' => 'No jobs from this partner are waiting.'];
        if ($partner->notify_via !== 'sms') return ['success' => false, 'error' => "{$partner->name} does not take SMS."];
        if (! $partner->messagingPhone()) return ['success' => false, 'error' => "{$partner->name} has no phone number."];

        $result = $this->deliver($partner->messagingPhone(), $this->partnerDigestMessage($partner, $orders), null, 'partner_jobs_awaiting');
        if ($result['success']) {
            foreach ($orders as $order) $this->recordSent($order, self::REMINDER, $stepsDone[$order->id] ?? null);
        }
        return $result;
    }

    /** Staff opened the WhatsApp link: count it as sent. A partner reminder covers all its waiting jobs. */
    public function recordWhatsApp(LensOrder $order, string $kind): void
    {
        $this->assertKind($kind);
        if ($order->isPartnerJob() && $kind === self::REMINDER && $order->partnerClinic) {
            foreach ($this->waitingForPartner($order->partnerClinic) as $waiting) $this->recordSent($waiting, $kind);
            return;
        }
        $this->recordSent($order, $kind);
    }

    public function recordPartnerWhatsApp(OpticalPartnerClinic $partner): void
    {
        foreach ($this->waitingForPartner($partner) as $order) $this->recordSent($order, self::REMINDER);
    }

    /** Called after an order is marked ready. Never blocks the status change. */
    public function notifyReady(LensOrder $order): ?array
    {
        if (! (OpticalSetting::first()?->ready_sms_auto ?? true)) return null;
        try {
            return $this->sendSms($order, self::READY);
        } catch (\Throwable $e) {
            Log::warning('Optical ready SMS failed', ['order' => $order->order_id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** How many scheduled reminder days have passed for this order. */
    public function stepsDue(LensOrder $order, array $schedule): int
    {
        $days = $order->daysAwaitingCollection();
        return count(array_filter($schedule, fn ($day) => $day <= $days));
    }

    /**
     * Automatic reminders now due. Own customers get one message each; partner jobs are
     * grouped so each partner gets a single message.
     *
     * @return array{orders: Collection<int, array{order: LensOrder, steps: int}>, partners: Collection<int, array{partner: OpticalPartnerClinic, steps: array<int, int>}>}
     */
    public function dueReminders(): array
    {
        $schedule = OpticalSetting::currentReminderSchedule();
        if ($schedule === []) return ['orders' => collect(), 'partners' => collect()];

        $due = LensOrder::with(['patient', 'refraction.consultation.patient', 'partnerClinic'])
            ->whereIn('status', self::WAITING)->get()
            ->map(fn (LensOrder $order) => ['order' => $order, 'steps' => $this->stepsDue($order, $schedule)])
            ->filter(fn (array $row) => $row['steps'] > (int) $row['order']->collection_reminders_sent);

        return [
            'orders' => $due->reject(fn (array $row) => $row['order']->isPartnerJob())->values(),
            'partners' => $due->filter(fn (array $row) => $row['order']->isPartnerJob() && $row['order']->partnerClinic)
                ->groupBy(fn (array $row) => $row['order']->partner_clinic_id)
                ->map(fn ($rows) => [
                    'partner' => $rows->first()['order']->partnerClinic,
                    'steps' => $rows->mapWithKeys(fn (array $row) => [$row['order']->id => $row['steps']])->all(),
                ])->values(),
        ];
    }

    /** @return array{success: bool, error: ?string} */
    private function deliver(string $phone, string $message, ?int $patientId, string $templateKey): array
    {
        if ($message === '') return ['success' => false, 'error' => 'The SMS template for this message is empty.'];
        $availability = SmsAvailability::check();
        if (! $availability['available']) return ['success' => false, 'error' => $availability['reason']];
        $result = app(SmsService::class)->send($phone, $message, $patientId, $templateKey);
        return ['success' => (bool) ($result['success'] ?? false), 'error' => $result['error'] ?? null];
    }

    private function recordSent(LensOrder $order, string $kind, ?int $stepsDone = null): void
    {
        if ($kind === self::READY) {
            $order->forceFill(['ready_notified_at' => now()])->save();
            return;
        }
        $order->forceFill([
            // Automatic sends jump to the schedule step reached, so a missed day is not resent.
            'collection_reminders_sent' => min(255, max((int) $order->collection_reminders_sent + ($stepsDone === null ? 1 : 0), (int) $stepsDone)),
            'last_collection_reminder_at' => now(),
        ])->save();
    }

    private function assertKind(string $kind): void
    {
        abort_unless(in_array($kind, [self::READY, self::REMINDER], true), 422);
    }
}
