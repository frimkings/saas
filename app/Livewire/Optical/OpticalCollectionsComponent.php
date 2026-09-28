<?php

namespace App\Livewire\Optical;

use App\Livewire\Optical\Concerns\ManagesOrderPanel;
use App\Models\LensOrder;
use App\Models\OpticalPartnerClinic;
use App\Models\OpticalSetting;
use App\Services\OpticalCollectionNotifier;
use App\Support\Messaging\SmsAvailability;
use App\Support\Optical\OrderPresenter;
use Livewire\Component;

/**
 * Glasses that are ready but not yet collected: how long they have waited, the money
 * still owed on them, and one-click SMS / WhatsApp reminders. Partner clinics' jobs are
 * reported to the partner, one reminder covering all of its waiting jobs.
 */
class OpticalCollectionsComponent extends Component
{
    use ManagesOrderPanel;


    /** Short note for the status-change flash message about the automatic ready SMS. */
    public static function readySmsNote(?array $result): string
    {
        if ($result === null) return '';
        return ($result['success'] ?? false)
            ? ' Customer notified by SMS.'
            : ' Ready SMS not sent: '.($result['error'] ?? 'unknown error').' Use WhatsApp from Awaiting Collection.';
    }

    public function sendSms(int $orderId, string $kind): void
    {
        $order = $this->awaiting()->findOrFail($orderId);
        $result = app(OpticalCollectionNotifier::class)->sendSms($order, $kind);
        $result['success']
            ? session()->flash('success', 'SMS sent to '.(app(OpticalCollectionNotifier::class)->recipient($order)['name'] ?? 'customer')." for {$order->order_id}.")
            : $this->addError('sms', 'SMS not sent: '.$result['error']);
    }

    public function recordWhatsApp(int $orderId, string $kind): void
    {
        $order = $this->awaiting()->findOrFail($orderId);
        app(OpticalCollectionNotifier::class)->recordWhatsApp($order, $kind);
    }

    public function sendPartnerSms(int $partnerId): void
    {
        $partner = OpticalPartnerClinic::findOrFail($partnerId);
        $result = app(OpticalCollectionNotifier::class)->sendPartnerDigest($partner);
        $result['success']
            ? session()->flash('success', "Reminder sent to {$partner->name} for all its waiting jobs.")
            : $this->addError('sms', 'SMS not sent: '.$result['error']);
    }

    public function recordPartnerWhatsApp(int $partnerId): void
    {
        app(OpticalCollectionNotifier::class)->recordPartnerWhatsApp(OpticalPartnerClinic::findOrFail($partnerId));
    }

    private function awaiting()
    {
        return LensOrder::whereIn('status', ['Ready for Collection', 'Ready']);
    }

    public function render()
    {
        // Every waiting job, longest wait first; search and the filters work in the browser.
        $all = $this->awaiting()->with(['patient', 'refraction.consultation.patient', 'partnerClinic', 'serviceLines'])->get();
        $orders = $all->sortByDesc(fn (LensOrder $order) => $order->daysAwaitingCollection())->values();
        // What is still owed per order (clinic orders: the patient's clinic bill).
        $owed = $all->mapWithKeys(fn (LensOrder $order) => [$order->id => OrderPresenter::money($order)]);
        $partnerJobs = $all->filter(fn (LensOrder $order) => $order->isPartnerJob() && $order->partnerClinic)->groupBy('partner_clinic_id');

        return view('livewire.optical.optical-collections-component', [
            'orders' => $orders,
            'notifier' => app(OpticalCollectionNotifier::class),
            'sms' => SmsAvailability::check(),
            'money' => $owed,
            'balanceHeld' => $orders->sum(fn (LensOrder $order) => $owed[$order->id]['balance']),
            'schedule' => OpticalSetting::currentReminderSchedule(),
            'partners' => $partnerJobs->map(fn ($jobs) => [
                'partner' => $jobs->first()->partnerClinic,
                'count' => $jobs->count(),
                'oldest' => $jobs->max(fn (LensOrder $order) => $order->daysAwaitingCollection()),
                'balance' => $jobs->sum(fn (LensOrder $order) => $owed[$order->id]['balance']),
            ])->sortByDesc('oldest')->values(),
        ])->layout('layouts.optical');
    }
}
