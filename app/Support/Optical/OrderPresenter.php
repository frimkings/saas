<?php

namespace App\Support\Optical;

use App\Models\LensOrder;

/** How an optical order is labelled on screen and what its next step is. */
class OrderPresenter
{
    public const STEPS = ['Pending' => 'Order placed', 'Sent to Lab' => 'Sent to lab', 'In Production' => 'In production', 'Ready for Collection' => 'Ready', 'Collected' => 'Collected'];

    /** @return array{0: string, 1: string} label and CSS class */
    public static function badge(string $status): array
    {
        return match ($status) {
            'Quotation' => ['Quotation', 'oo-b-purple'],
            'Pending' => ['Pending lab', 'oo-b-grey'],
            'Sent to Lab' => ['Sent to lab', 'oo-b-teal'],
            'In Production' => ['In production', 'oo-b-blue'],
            'Ready for Collection', 'Ready' => ['Ready', 'oo-b-amber'],
            'Collected' => ['Collected', 'oo-b-green'],
            'Cancelled' => ['Cancelled', 'oo-b-red'],
            default => [$status, 'oo-b-grey'],
        };
    }

    /**
     * The single most likely next step: [label, Livewire call, confirm text or null].
     * $bench uses the workshop wording (start edging / QC pass) for lab staff.
     */
    public static function nextStep(LensOrder $order, bool $bench = false): ?array
    {
        return match ($order->status) {
            'Quotation' => $bench ? null : ['Convert to order', "convertQuotationToOrder({$order->id})", null],
            'Pending' => $bench ? ['Start edging', "updateStatus({$order->id}, 'In Production')", null] : ['Send to lab', "openSendToLab({$order->id})", null],
            'Sent to Lab' => $bench ? ['Start edging', "updateStatus({$order->id}, 'In Production')", null] : ['Mark ready', "updateStatus({$order->id}, 'Ready for Collection')", null],
            'In Production' => [$bench ? 'QC pass · ready' : 'Mark ready', "updateStatus({$order->id}, 'Ready for Collection')", $bench ? 'Quality check passed? The customer is told the glasses are ready.' : null],
            'Ready for Collection', 'Ready' => ['Mark collected', "updateStatus({$order->id}, 'Collected')", 'Hand the glasses to the customer and close this order?'],
            default => null,
        };
    }

    /** Money for the order: clinic orders use the patient's clinic bill. */
    public static function money(LensOrder $order): array
    {
        $clinicBill = ClinicSpectacleBilling::appliesTo($order) ? ClinicSpectacleBilling::summary($order->refraction) : null;
        $total = $order->total;

        return [
            'total' => $total,
            'paid' => $clinicBill ? (float) $clinicBill['paid'] : (float) $order->paid_amount,
            'balance' => $clinicBill ? (float) $clinicBill['balance'] : max(0, $total - (float) $order->paid_amount),
            'clinicBill' => $clinicBill,
            'onAccount' => $order->order_source === 'partner' && $order->partner_billing_terms === 'on_account',
            'canTakePayment' => $order->sale_id && ! in_array($order->status, ['Quotation', 'Cancelled'], true),
        ];
    }

    /** Lab docket saved as JSON in notes, or null for plain-text notes. */
    public static function docket(LensOrder $order): ?array
    {
        $notes = is_string($order->notes) ? ltrim($order->notes) : '';
        if (! in_array(substr($notes, 0, 1), ['{', '['], true)) return null;
        $docket = json_decode($notes, true);
        return is_array($docket) ? $docket : null;
    }

    public static function awaitedLenses(LensOrder $order)
    {
        return $order->lensLines->where('source', 'special_order')->where('status', 'ordered');
    }
}
