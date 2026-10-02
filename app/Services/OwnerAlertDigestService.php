<?php

namespace App\Services;

use App\Mail\OwnerAlertsMail;
use App\Models\Branch;
use App\Models\BranchInventoryItem;
use App\Models\Clinic;
use App\Models\LensOrder;
use App\Models\OpticalProductStock;
use App\Models\OwnerAlertItem;
use App\Models\OwnerEmail;
use App\Models\PurchaseOrder;
use App\Models\RecurringExpense;
use App\Services\Reminders\AttentionItems;
use App\Support\Feature;
use App\Support\Tenancy\ActAsClinic;
use Illuminate\Support\Carbon;

/**
 * The morning alerts email to the clinic owner: things that need attention across every
 * branch. It goes from 10 AM clinic time, only when something new came up, and each item is
 * reported once per stage (low, then out of stock; 90 days, 30 days, then expired; bill due,
 * then overdue ...). An item that clears and comes back later is reported again.
 */
class OwnerAlertDigestService
{
    public const SEND_HOUR = 10;
    public const BILL_DAYS = 7;
    private const SHOWN_PER_SECTION = 20;

    /** Section => [heading, what the owner should do, link route]. In email order. */
    public const SECTIONS = [
        'low_stock' => ['Low stock', 'Reorder these before they run out.'],
        'expiry' => ['Expiring stock', 'Sell or return these before they expire; take expired stock off the shelf.'],
        'bills' => ['Supplier bills', 'Pay these to keep suppliers happy.'],
        'recurring' => ['Regular expenses due', 'Pay and record these.'],
        'late_pickup' => ['Spectacles past their promised date', 'Chase the lab, and tell the patients the new date.'],
        'lab' => ['Late from the lab', 'Chase the lab, and let the customers know.'],
        'uncollected' => ['Glasses not collected', 'Call these customers to come in.'],
    ];

    public function __construct(private readonly OwnerMailer $mailer, private readonly OwnerSummaryService $summaries) {}

    /** Check and email if due. Safe to run every hour. Returns the email status, or null when nothing was sent. */
    public function sendDue(Clinic $clinic, ?Carbon $now = null): ?string
    {
        $local = ($now ?? Carbon::now())->copy()->setTimezone($clinic->default_timezone ?: config('app.timezone'));
        if ($local->hour < self::SEND_HOUR || ! $this->summaries->includes($clinic, Feature::MORNING_ALERTS)) return null;

        $date = $local->toDateString();
        $key = 'alerts:' . $date;
        // Once today's email exists, only a failed send is tried again; nothing is re-checked.
        $email = OwnerEmail::where('dedupe_key', $clinic->id . ':' . $key)->first();
        if (! $email) $this->sync($clinic, $local);

        $new = OwnerAlertItem::where('clinic_id', $clinic->id)->whereNull('resolved_at')->whereDateIndexed('alerted_on', $date)->get();
        if ($new->isEmpty()) return null;

        $count = $new->count();
        $subject = ($count === 1 ? '1 thing needs' : "{$count} things need") . ' your attention - ' . $clinic->name;

        return $this->mailer->send($clinic, 'morning_alerts', $key, $subject, fn () => new OwnerAlertsMail($this->build($clinic, $new, $date)));
    }

    /** Record what needs attention now: new items are dated today, cleared ones are closed. */
    public function sync(Clinic $clinic, Carbon $local): void
    {
        $items = $this->current($clinic, $local);
        $date = $local->toDateString();
        $open = OwnerAlertItem::where('clinic_id', $clinic->id)->whereNull('resolved_at')->get()
            ->keyBy(fn ($row) => "{$row->section}|{$row->item_key}|{$row->stage}");

        foreach ($items as $item) {
            $id = "{$item['section']}|{$item['key']}|{$item['stage']}";
            $text = ['title' => mb_substr($item['title'], 0, 255), 'detail' => mb_substr((string) $item['detail'], 0, 255), 'branch_name' => $item['branch']];
            if ($row = $open->pull($id)) {
                $row->update($text);
                continue;
            }
            OwnerAlertItem::updateOrCreate(
                ['clinic_id' => $clinic->id, 'section' => $item['section'], 'item_key' => $item['key'], 'stage' => $item['stage']],
                $text + ['alerted_on' => $date, 'resolved_at' => null],
            );
        }
        // Restocked, sold, paid, returned or collected since.
        OwnerAlertItem::whereIn('id', $open->pluck('id'))->update(['resolved_at' => now()]);
    }

    /** Expiring and expired stock batches; keys stay as they were for clinic batches ("lot"). */
    private function addExpiring(callable $add, string $line, Carbon $today): void
    {
        $prefix = ['inventory_lot' => 'lot', 'product' => 'prod', 'optical_lot' => 'olot'];
        $stage = ['stock_expired' => 'expired', 'stock_expiring_soon' => '30', 'stock_expiring' => '90'];
        foreach (app(AttentionItems::class)->stockItems($line, $today) as $item) {
            $add('expiry', $prefix[$item['type']] . $item['id'], $stage[$item['rule']], $item['title'], $item['detail']);
        }
    }

    /** Everything that needs attention right now, across every branch. */
    public function current(Clinic $clinic, Carbon $local): array
    {
        $items = [];
        $today = Carbon::parse($local->toDateString());
        $money = fn ($amount) => ($clinic->default_currency ?: currency()) . ' ' . number_format((float) $amount, 2);

        ActAsClinic::eachBranch($clinic, function (Branch $branch, bool $clinical, bool $optical) use (&$items, $today, $money) {
            $add = function (string $section, string $key, string $stage, string $title, ?string $detail) use (&$items, $branch) {
                $items[] = ['section' => $section, 'key' => $key, 'stage' => $stage, 'title' => $title, 'detail' => $detail, 'branch' => $branch->name];
            };
            $inventory = $clinical && LicenseService::has(Feature::INVENTORY);

            if ($inventory) {
                BranchInventoryItem::with('product:id,name,made_to_order')->where('is_active', true)->where('reorder_level', '>', 0)
                    ->whereColumn('quantity', '<=', 'reorder_level')->get()
                    ->each(fn ($stock) => $stock->product && ! $stock->product->made_to_order && $add('low_stock', 'c' . $stock->id, $stock->quantity > 0 ? 'low' : 'out', $stock->product->name,
                        $stock->quantity > 0 ? "{$stock->quantity} left (reorder at {$stock->reorder_level})" : 'Out of stock'));

                // The same batches staff see on Needs attention (the clinic's expiry window, stock still on the shelf).
                $this->addExpiring($add, AttentionItems::CLINIC, $today);

                PurchaseOrder::with('supplier:id,name')->whereNotIn('invoice_status', ['none', 'paid'])->whereNotNull('invoice_due_date')
                    ->whereDateIndexed('invoice_due_date', '<=', $today->copy()->addDays(self::BILL_DAYS))->get()
                    ->each(function ($po) use ($add, $today, $money) {
                        $owed = (float) $po->invoice_amount - (float) $po->paid_amount;
                        if ($owed <= 0.004) return;
                        $due = Carbon::parse($po->invoice_due_date);
                        $overdue = $due->lt($today);
                        $add('bills', 'po' . $po->id, $overdue ? 'overdue' : 'due', trim(($po->supplier?->name ?? 'Supplier') . ' · ' . $po->po_number),
                            $money($owed) . ($overdue ? ' overdue since ' : ' due ') . $due->format('j M Y'));
                    });
            }

            if (LicenseService::has(Feature::EXPENSE_TRACKING)) {
                RecurringExpense::where('is_active', true)->whereDateIndexed('next_due_date', '<=', $today)->get()
                    ->each(fn ($expense) => $add('recurring', 'r' . $expense->id . ':' . $expense->next_due_date->toDateString(), 'due', $expense->description,
                        trim($money($expense->amount) . ($expense->payee ? " to {$expense->payee}" : '') . ' · due ' . $expense->next_due_date->format('j M Y'))));
            }

            if ($optical) {
                OpticalProductStock::with('product:id,name,is_active')->where('reorder_level', '>', 0)->whereColumn('quantity', '<=', 'reorder_level')->get()
                    ->each(fn ($stock) => $stock->product?->is_active && $add('low_stock', 'o' . $stock->id, $stock->quantity > 0 ? 'low' : 'out', $stock->product->name,
                        $stock->quantity > 0 ? "{$stock->quantity} left (reorder at {$stock->reorder_level})" : 'Out of stock'));

                LensOrder::whereIn('status', ['In Lab', 'In Production'])->whereNotNull('expected_back_at')->whereDateIndexed('expected_back_at', '<', $today)->get()
                    ->each(fn ($order) => $add('lab', 'lab' . $order->id, 'late', "{$order->order_id} · {$order->display_customer_name}",
                        'Expected back ' . $order->expected_back_at->format('j M') . ' (' . (int) $order->expected_back_at->diffInDays($today) . ' days late)'));

            }

            // Spectacles, for clinic orders and optical jobs alike: past the promised date, and
            // not collected after the clinic's chosen number of days (Settings → Reminders).
            if ($optical) {
                $this->addExpiring($add, AttentionItems::OPTICAL, $today);
            }

            if ($clinical || $optical) {
                $ownerDays = AttentionItems::thresholds()['owner_uncollected_days'];
                // Orders older than the clinic's "stop chasing" days wait on the staff tidy-up list instead.
                $staleFrom = $today->copy()->subDays(AttentionItems::thresholds()['stale_days']);
                $orders = fn () => LensOrder::query()->whereNull('cancelled_at')
                    ->when(! $optical, fn ($q) => $q->whereNotNull('refraction_id'));
                // Clinic orders get their own keys; optical jobs keep the ones they always had.
                $key = fn ($order, string $kind) => ($order->refraction_id ? 'c' : '') . $kind . $order->id;

                $orders()->whereNotIn('status', [...LensOrder::READY, 'Collected', 'Cancelled', 'Quotation'])
                    ->whereNotNull('pickUpDate')->whereDateIndexed('pickUpDate', '<', $today->toDateString())
                    ->whereDateIndexed('pickUpDate', '>=', $staleFrom->toDateString())->get()
                    ->each(function ($order) use ($add, $today, $key) {
                        $promised = Carbon::parse($order->pickUpDate);
                        $add('late_pickup', $key($order, 'late'), 'late', "{$order->order_id} · {$order->display_customer_name}",
                            'Promised ' . $promised->format('j M') . ' (' . (int) $promised->copy()->startOfDay()->diffInDays($today) . ' days late) · ' . $order->status);
                    });

                $orders()->whereIn('status', LensOrder::READY)
                    ->whereRaw('COALESCE(ready_at, updated_at) <= ?', [$today->copy()->subDays($ownerDays)])->get()
                    ->each(function ($order) use ($add, $today, $money, $key, $ownerDays) {
                        $days = (int) ($order->ready_at ?? $order->updated_at)->copy()->startOfDay()->diffInDays($today);
                        $balance = $order->refraction_id ? 0 : round($order->total - (float) $order->paid_amount, 2);
                        $stage = $days >= 90 ? '90' : ($days >= 60 ? '60' : ($days >= 30 ? '30' : (string) $ownerDays));
                        $add('uncollected', $key($order, 'ready'), $stage, "{$order->order_id} · {$order->display_customer_name}",
                            "Ready for {$days} days" . ($balance > 0 ? ' · ' . $money($balance) . ' still to pay' : ''));
                    });
            }
        });

        return $items;
    }

    /** What the email shows: today's new items by section, and how many older ones are still open. */
    private function build(Clinic $clinic, $new, string $date): array
    {
        $older = OwnerAlertItem::where('clinic_id', $clinic->id)->whereNull('resolved_at')->whereDateIndexed('alerted_on', '<', $date)
            ->selectRaw('section, COUNT(*) as n')->groupBy('section')->pluck('n', 'section');
        $multiBranch = $clinic->branches()->where('is_active', true)->count() > 1;
        $links = [
            'low_stock' => $new->where('section', 'low_stock')->every(fn ($row) => str_starts_with($row->item_key, 'o')) ? route('optical.stock') : route('admin.inventory-alerts'),
            'expiry' => $new->where('section', 'expiry')->every(fn ($row) => str_starts_with($row->item_key, 'olot')) ? route('optical.stock') : route('admin.inventory-alerts'),
            'bills' => route('admin.purchase-orders'),
            'recurring' => route('admin.expenses'),
            'late_pickup' => $new->where('section', 'late_pickup')->every(fn ($row) => str_starts_with($row->item_key, 'clate')) ? route('secretary.spectacles') : route('optical.orders'),
            'lab' => route('optical.jobs'),
            'uncollected' => $new->where('section', 'uncollected')->every(fn ($row) => str_starts_with($row->item_key, 'cready')) ? route('secretary.spectacles') : route('optical.collections'),
        ];

        $sections = [];
        foreach (self::SECTIONS as $section => [$heading, $advice]) {
            $rows = $new->where('section', $section)->sortBy('title')->values();
            if ($rows->isEmpty()) continue;
            $sections[$section] = [
                'heading' => $heading,
                'advice' => $advice,
                'rows' => $rows->take(self::SHOWN_PER_SECTION)->map(fn ($row) => [
                    'title' => $row->title, 'detail' => $row->detail,
                    'branch' => $multiBranch ? $row->branch_name : null,
                    'urgent' => in_array($row->stage, ['out', 'expired', 'overdue', '90'], true),
                ])->all(),
                'more' => max(0, $rows->count() - self::SHOWN_PER_SECTION),
                'stillOpen' => (int) ($older[$section] ?? 0),
                'url' => $links[$section],
            ];
        }

        return [
            'clinic' => $clinic->name,
            'date' => Carbon::parse($date)->format('D j M Y'),
            'sections' => $sections,
        ];
    }
}
