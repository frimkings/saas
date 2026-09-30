<?php

namespace App\Services;

use App\Mail\OwnerAlertsMail;
use App\Models\Branch;
use App\Models\BranchInventoryItem;
use App\Models\Clinic;
use App\Models\InventoryLot;
use App\Models\LensOrder;
use App\Models\OpticalProductStock;
use App\Models\OwnerAlertItem;
use App\Models\OwnerEmail;
use App\Models\PurchaseOrder;
use App\Models\RecurringExpense;
use App\Support\Feature;
use App\Support\Tenancy\ActAsClinic;
use Illuminate\Support\Carbon;

/**
 * The morning alerts email to the clinic owner: things that need attention across every
 * branch. It goes from 7 AM clinic time, only when something new came up, and each item is
 * reported once per stage (low, then out of stock; 90 days, 30 days, then expired; bill due,
 * then overdue ...). An item that clears and comes back later is reported again.
 */
class OwnerAlertDigestService
{
    public const SEND_HOUR = 7;
    public const EXPIRY_DAYS = 90;
    public const BILL_DAYS = 7;
    public const UNCOLLECTED_DAYS = 30;
    private const SHOWN_PER_SECTION = 20;

    /** Section => [heading, what the owner should do, link route]. In email order. */
    public const SECTIONS = [
        'low_stock' => ['Low stock', 'Reorder these before they run out.'],
        'expiry' => ['Expiring stock', 'Sell or return these before they expire; take expired stock off the shelf.'],
        'bills' => ['Supplier bills', 'Pay these to keep suppliers happy.'],
        'recurring' => ['Regular expenses due', 'Pay and record these.'],
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

        $new = OwnerAlertItem::where('clinic_id', $clinic->id)->whereNull('resolved_at')->whereDate('alerted_on', $date)->get();
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

                InventoryLot::with('product:id,name,made_to_order')->where('quantity', '>', 0)->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<=', $today->copy()->addDays(self::EXPIRY_DAYS))->get()
                    ->each(function ($lot) use ($add, $today) {
                        if (! $lot->product || $lot->product->made_to_order) return;
                        $days = (int) $today->diffInDays($lot->expiry_date, false);
                        $when = $days < 0 ? 'expired ' . $lot->expiry_date->format('j M Y') : 'expires ' . $lot->expiry_date->format('j M Y') . ($days === 0 ? ' (today)' : " (in {$days} days)");
                        $add('expiry', 'lot' . $lot->id, $days < 0 ? 'expired' : ($days <= 30 ? '30' : '90'), $lot->product->name,
                            trim(($lot->batch_number ? "Batch {$lot->batch_number} · " : '') . "{$lot->quantity} left · {$when}"));
                    });

                PurchaseOrder::with('supplier:id,name')->whereNotIn('invoice_status', ['none', 'paid'])->whereNotNull('invoice_due_date')
                    ->whereDate('invoice_due_date', '<=', $today->copy()->addDays(self::BILL_DAYS))->get()
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
                RecurringExpense::where('is_active', true)->whereDate('next_due_date', '<=', $today)->get()
                    ->each(fn ($expense) => $add('recurring', 'r' . $expense->id . ':' . $expense->next_due_date->toDateString(), 'due', $expense->description,
                        trim($money($expense->amount) . ($expense->payee ? " to {$expense->payee}" : '') . ' · due ' . $expense->next_due_date->format('j M Y'))));
            }

            if ($optical) {
                OpticalProductStock::with('product:id,name,is_active')->where('reorder_level', '>', 0)->whereColumn('quantity', '<=', 'reorder_level')->get()
                    ->each(fn ($stock) => $stock->product?->is_active && $add('low_stock', 'o' . $stock->id, $stock->quantity > 0 ? 'low' : 'out', $stock->product->name,
                        $stock->quantity > 0 ? "{$stock->quantity} left (reorder at {$stock->reorder_level})" : 'Out of stock'));

                LensOrder::whereIn('status', ['In Lab', 'In Production'])->whereNotNull('expected_back_at')->whereDate('expected_back_at', '<', $today)->get()
                    ->each(fn ($order) => $add('lab', 'lab' . $order->id, 'late', "{$order->order_id} · {$order->display_customer_name}",
                        'Expected back ' . $order->expected_back_at->format('j M') . ' (' . (int) $order->expected_back_at->diffInDays($today) . ' days late)'));

                LensOrder::whereIn('status', ['Ready', 'Ready for Collection'])->whereNotNull('ready_at')->where('ready_at', '<=', $today->copy()->subDays(self::UNCOLLECTED_DAYS))->get()
                    ->each(function ($order) use ($add, $today, $money) {
                        $days = (int) $order->ready_at->copy()->startOfDay()->diffInDays($today);
                        $balance = round($order->total - (float) $order->paid_amount, 2);
                        $add('uncollected', 'ready' . $order->id, $days >= 90 ? '90' : ($days >= 60 ? '60' : '30'), "{$order->order_id} · {$order->display_customer_name}",
                            "Ready for {$days} days" . ($balance > 0 ? ' · ' . $money($balance) . ' still to pay' : ''));
                    });
            }
        });

        return $items;
    }

    /** What the email shows: today's new items by section, and how many older ones are still open. */
    private function build(Clinic $clinic, $new, string $date): array
    {
        $older = OwnerAlertItem::where('clinic_id', $clinic->id)->whereNull('resolved_at')->whereDate('alerted_on', '<', $date)
            ->selectRaw('section, COUNT(*) as n')->groupBy('section')->pluck('n', 'section');
        $multiBranch = $clinic->branches()->where('is_active', true)->count() > 1;
        $links = [
            'low_stock' => $new->where('section', 'low_stock')->every(fn ($row) => str_starts_with($row->item_key, 'o')) ? route('optical.stock') : route('admin.inventory-alerts'),
            'expiry' => route('admin.inventory-alerts'),
            'bills' => route('admin.purchase-orders'),
            'recurring' => route('admin.expenses'),
            'lab' => route('optical.jobs'),
            'uncollected' => route('optical.collections'),
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
