<?php

namespace App\Services\Scans;

use App\Models\ScanCommission;
use App\Models\ScanOrder;
use App\Models\ScanOrderItem;
use App\Models\ScanPartnerClinic;
use App\Models\Sales;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Referral and scan reports for a run of days. An order counts on the day it was registered;
 * cancelled orders are left out. "Billed" is what was charged, "Paid" what has come in on those
 * bills, "Commission" what referrers earned on them (corrections included, voids not).
 */
class ScanReports
{
    public const DORMANT_DAYS = 60;

    public function for(CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = ScanOrder::with(['partner:id,name,code', 'partnerDoctor:id,name', 'orderedBy:id,name', 'items:id,scan_order_id,scan_name,quantity,line_total'])
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();
        $paid = Sales::withoutGlobalScope('branch')->whereIn('id', $orders->pluck('sale_id')->filter())->pluck('amount_paid', 'id');
        $commission = ScanCommission::whereIn('scan_order_id', $orders->pluck('id'))->where('status', '!=', 'void')
            ->selectRaw('scan_order_id, SUM(amount) as amount')->groupBy('scan_order_id')->pluck('amount', 'scan_order_id');

        $row = fn (Collection $group) => [
            'orders' => $group->count(),
            'patients' => $group->pluck('patient_id')->unique()->count(),
            'scans' => (int) $group->sum(fn ($order) => $order->items->sum('quantity')),
            'billed' => round((float) $group->whereNotNull('sale_id')->sum('total_amount'), 2),
            'paid' => round((float) $group->sum(fn ($order) => (float) ($paid[$order->sale_id] ?? 0)), 2),
            'commission' => round((float) $group->sum(fn ($order) => (float) ($commission[$order->id] ?? 0)), 2),
        ];

        $partners = $orders->where('source', 'partner')->groupBy('scan_partner_clinic_id')
            ->map(fn ($group) => ['name' => $group->first()->partner?->name ?? 'Partner clinic', 'code' => $group->first()->partner?->code] + $row($group))
            ->sortByDesc('orders')->values();

        $sources = collect(ScanOrder::SOURCES)->map(fn ($label, $key) => ['label' => $label] + $row($orders->where('source', $key)));

        // Billed scans only: an order not yet billed is not done, and not charged.
        $scans = $orders->whereNotNull('sale_id')->flatMap(fn ($order) => $order->items->map(fn ($item) => ['name' => $item->scan_name, 'source' => $order->source, 'quantity' => (int) $item->quantity, 'amount' => (float) $item->line_total]))
            ->groupBy('name')->map(fn ($lines, $name) => [
                'name' => $name, 'count' => $lines->sum('quantity'), 'amount' => round($lines->sum('amount'), 2),
                'partner' => $lines->where('source', 'partner')->sum('quantity'), 'internal' => $lines->where('source', 'internal')->sum('quantity'), 'self' => $lines->where('source', 'self')->sum('quantity'),
            ])->sortByDesc('count')->values();

        $doctors = $orders->filter(fn ($order) => $order->source === 'internal' ? $order->ordered_by : $order->scan_partner_doctor_id)
            ->groupBy(fn ($order) => $order->source === 'internal' ? 'u'.$order->ordered_by : 'p'.$order->scan_partner_doctor_id)
            ->map(fn ($group) => [
                'name' => $group->first()->source === 'internal' ? ($group->first()->orderedBy?->name ?? 'Doctor') : ($group->first()->partnerDoctor?->name ?? 'Doctor'),
                'where' => $group->first()->source === 'internal' ? 'Our doctor' : ($group->first()->partner?->name ?? 'Partner clinic'),
                'orders' => $group->count(), 'billed' => round((float) $group->whereNotNull('sale_id')->sum('total_amount'), 2),
            ])->sortByDesc('orders')->take(10)->values();

        $hours = fn ($pairs) => $pairs->isEmpty() ? null : round($pairs->avg() / 60, 1);
        $turnaround = [
            'to_report' => $hours($orders->filter(fn ($order) => $order->checked_out_at && $order->reported_at)->map(fn ($order) => $order->checked_out_at->diffInMinutes($order->reported_at))),
            'to_send' => $hours($orders->filter(fn ($order) => $order->reported_at && $order->results_sent_at)->map(fn ($order) => $order->reported_at->diffInMinutes($order->results_sent_at))),
            'reported' => $orders->whereNotNull('reported_at')->count(),
            'sent' => $orders->whereNotNull('results_sent_at')->count(),
        ];

        return ['totals' => $row($orders), 'partners' => $partners, 'sources' => $sources, 'scans' => $scans, 'doctors' => $doctors,
            'turnaround' => $turnaround, 'dormant' => $this->dormant()];
    }

    /** Active partners with no referral for DORMANT_DAYS days (or none ever, once they have been listed that long). */
    public function dormant(): Collection
    {
        $since = now()->subDays(self::DORMANT_DAYS);
        $last = ScanOrder::withoutGlobalScope('branch')->where('source', 'partner')->where('status', '!=', 'cancelled')
            ->selectRaw('scan_partner_clinic_id, MAX(created_at) as last_at')->groupBy('scan_partner_clinic_id')->pluck('last_at', 'scan_partner_clinic_id');

        return ScanPartnerClinic::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'phone', 'created_at'])
            ->filter(fn ($partner) => isset($last[$partner->id]) ? \Illuminate\Support\Carbon::parse($last[$partner->id])->lt($since) : $partner->created_at->lt($since))
            ->map(fn ($partner) => ['name' => $partner->name, 'code' => $partner->code, 'phone' => $partner->phone,
                'last' => isset($last[$partner->id]) ? \Illuminate\Support\Carbon::parse($last[$partner->id]) : null])
            ->values();
    }

    public function csv(CarbonInterface $from, CarbonInterface $to, string $clinicName): StreamedResponse
    {
        $report = $this->for($from, $to);
        $name = 'scan-referrals-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($report, $from, $to, $clinicName) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $money = fn ($n) => number_format((float) $n, 2, '.', '');
            fputcsv($out, ['Diagnostic scan referrals', $from->format('d M Y').' to '.$to->format('d M Y'), $clinicName]);
            fputcsv($out, ['Orders counted on the day they were registered; cancelled orders left out. Amounts in '.\App\Models\Setting::currency().'.']);
            fputcsv($out, []);
            fputcsv($out, ['Referrals by partner clinic']);
            fputcsv($out, ['Code', 'Partner clinic', 'Orders', 'Patients', 'Scans', 'Billed', 'Paid', 'Commission', 'Kept by the clinic']);
            foreach ($report['partners'] as $p) {
                fputcsv($out, [$p['code'], $p['name'], $p['orders'], $p['patients'], $p['scans'], $money($p['billed']), $money($p['paid']), $money($p['commission']), $money($p['paid'] - $p['commission'])]);
            }
            fputcsv($out, []);
            fputcsv($out, ['By source']);
            fputcsv($out, ['Source', 'Orders', 'Patients', 'Scans', 'Billed', 'Paid', 'Commission']);
            foreach ($report['sources'] as $s) {
                fputcsv($out, [$s['label'], $s['orders'], $s['patients'], $s['scans'], $money($s['billed']), $money($s['paid']), $money($s['commission'])]);
            }
            $t = $report['totals'];
            fputcsv($out, ['All', $t['orders'], $t['patients'], $t['scans'], $money($t['billed']), $money($t['paid']), $money($t['commission'])]);
            fputcsv($out, []);
            fputcsv($out, ['By scan']);
            fputcsv($out, ['Scan (billed orders)', 'Scans', 'Billed', 'From partners', 'From our doctors', 'Self-referred']);
            foreach ($report['scans'] as $s) {
                fputcsv($out, [$s['name'], $s['count'], $money($s['amount']), $s['partner'], $s['internal'], $s['self']]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
