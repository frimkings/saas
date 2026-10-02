<?php

namespace App\Services\Reminders;

use App\Models\Appointments;
use App\Models\AttentionAction;
use App\Models\AuditTrail;
use App\Models\BranchInventoryItem;
use App\Models\InventoryLot;
use App\Models\LensOrder;
use App\Models\OpticalProductStock;
use App\Models\OpticalStockLot;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Stock;
use App\Services\Inventory\BranchInventoryService;
use App\Services\OpticalStockLedgerService;
use App\Support\Tenancy\TenantCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Needs attention" for clinic and optical staff: what to act on today, from data the
 * clinic already has. Everyone sees everything; marking an item done or snoozing it (with a
 * note) clears it for the whole team. Thresholds are the clinic's (Settings → Reminders).
 *
 *  - appt_soon:           appointments in the next few hours, patient not yet arrived
 *  - appt_missed:         today's appointments whose time has passed with no arrival
 *  - order_due:           spectacles promised today or within a few days, not yet ready
 *  - order_late:          spectacles past their promised date, not yet ready
 *  - order_uncollected:   glasses ready for a few days and not collected
 *  - stock_expired:       stock past its expiry date still on the shelf (stays until a Super Admin removes it)
 *  - stock_expiring_soon: stock expiring within 30 days
 *  - stock_expiring:      stock expiring within the clinic's window (3 months by default)
 *
 * Orders late or uncollected for longer than the clinic's "stop chasing" days are not chased
 * here; they wait on a tidy-up list (staleOrders) until someone marks them collected.
 *
 * The clinic list covers the clinic's own spectacle orders (from a refraction) and clinic
 * stock batches; the optical list covers every job on the optical shop's Orders page and
 * optical stock batches. Appointments are clinic only.
 */
class AttentionItems
{
    public const CLINIC = 'clinic';
    public const OPTICAL = 'optical';

    /** rule => [heading, what to do, subject type ('stock' covers the three kinds of batch)] */
    public const RULES = [
        'appt_soon'           => ['Appointments coming up', 'Make sure these patients are on their way.', 'appointment'],
        'appt_missed'         => ['Missed today', 'Call to find out, and rebook.', 'appointment'],
        'order_late'          => ['Spectacles past their promised date', 'Chase the lab and tell the patient the new date.', 'lens_order'],
        'order_due'           => ['Spectacles due soon', 'Check they will be ready on time.', 'lens_order'],
        'order_uncollected'   => ['Ready, not collected', 'Call the patient to come in.', 'lens_order'],
        'stock_expired'       => ['Expired stock', 'Take it off the shelf now. A Super Admin removes it from stock.', 'stock'],
        'stock_expiring_soon' => ['Expiring within 30 days', 'Use or sell these first, or return them to the supplier.', 'stock'],
        'stock_expiring'      => ['Expiring soon', 'Plan to use or sell these before they expire.', 'stock'],
    ];

    /** Kinds of stock batch a stock reminder can be about. */
    public const STOCK_TYPES = ['inventory_lot', 'product', 'optical_lot'];

    public const DEFAULTS = ['appointment_hours' => 2, 'due_days' => 1, 'uncollected_days' => 3, 'owner_uncollected_days' => 14,
        'stale_days' => 30, 'expiry_days' => 90];

    /** Stock this close to expiry moves to the "within 30 days" group. */
    public const EXPIRY_SOON_DAYS = 30;

    /** Appointment statuses that mean the patient has not arrived yet. */
    private const NOT_ARRIVED = ['Pending', 'Confirmed', 'Called', 'Couldnt Answer'];

    /** A patient this late for today's appointment counts as missed. */
    private const MISSED_AFTER_MINUTES = 30;

    private const PER_GROUP = 50;

    /** The clinic's thresholds, with sane bounds. */
    public static function thresholds(): array
    {
        $s = Setting::getSettings();
        $pick = fn (string $column, string $key, int $max) => max(0, min($max, (int) ($s->{$column} ?? self::DEFAULTS[$key])));
        $uncollected = max(1, $pick('reminder_uncollected_days', 'uncollected_days', 90));

        return [
            'appointment_hours'      => max(1, $pick('reminder_appointment_hours', 'appointment_hours', 24)),
            'due_days'               => $pick('reminder_due_days', 'due_days', 14),
            'uncollected_days'       => $uncollected,
            'owner_uncollected_days' => max(1, $pick('reminder_owner_uncollected_days', 'owner_uncollected_days', 365)),
            // Must leave room for the "ready, not collected" reminder before an order goes stale.
            'stale_days'             => max(7, $uncollected + 1, $pick('reminder_stale_days', 'stale_days', 365)),
            'expiry_days'            => max(self::EXPIRY_SOON_DAYS, $pick('reminder_expiry_days', 'expiry_days', 365)),
        ];
    }

    /**
     * Today's items for one side, grouped by rule (empty groups left out).
     *
     * @return array<string, array{title: string, hint: string, items: Collection}>
     */
    public function groups(string $line): array
    {
        $t = self::thresholds();
        $now = Carbon::now();
        $today = Carbon::today();
        $groups = [];

        if ($line === self::CLINIC) {
            $base = fn () => Appointments::with('patient:id,name,contact,pxnumber')
                ->whereIn('status', self::NOT_ARRIVED)->orderBy('scheduled_at');
            $groups['appt_soon'] = $base()->whereBetween('scheduled_at', [$now, $now->copy()->addHours($t['appointment_hours'])])->limit(self::PER_GROUP)->get()
                ->map(fn ($a) => $this->appointmentItem($a, 'appt_soon', 'At ' . $a->scheduled_at->format('H:i')));
            $groups['appt_missed'] = Appointments::with('patient:id,name,contact,pxnumber')
                ->whereIn('status', [...self::NOT_ARRIVED, 'Missed'])
                ->whereBetween('scheduled_at', [$today, $now->copy()->subMinutes(self::MISSED_AFTER_MINUTES)])
                ->orderBy('scheduled_at')->limit(self::PER_GROUP)->get()
                ->map(fn ($a) => $this->appointmentItem($a, 'appt_missed', 'Was due at ' . $a->scheduled_at->format('H:i')));
        }

        $orders = fn () => $this->orders($line);
        $staleFrom = $today->copy()->subDays($t['stale_days']);

        $groups['order_late'] = $this->open($orders())->whereNotNull('pickUpDate')
            ->whereDateIndexed('pickUpDate', '<', $today->toDateString())
            ->whereDateIndexed('pickUpDate', '>=', $staleFrom->toDateString())
            ->orderBy('pickUpDate')->limit(self::PER_GROUP)->get()
            ->map(fn ($o) => $this->orderItem($o, 'order_late', 'Promised ' . Carbon::parse($o->pickUpDate)->format('j M') . ' · ' . $this->daysAgo(Carbon::parse($o->pickUpDate)) . ' late · ' . $o->status));
        $groups['order_due'] = $this->open($orders())->whereNotNull('pickUpDate')
            ->whereDateIndexed('pickUpDate', '>=', $today->toDateString())
            ->whereDateIndexed('pickUpDate', '<=', $today->copy()->addDays($t['due_days'])->toDateString())
            ->orderBy('pickUpDate')->limit(self::PER_GROUP)->get()
            ->map(fn ($o) => $this->orderItem($o, 'order_due', 'Promised ' . (Carbon::parse($o->pickUpDate)->isToday() ? 'today' : Carbon::parse($o->pickUpDate)->format('D j M')) . ' · ' . $o->status));
        $groups['order_uncollected'] = $orders()->whereIn('status', LensOrder::READY)
            ->whereRaw('COALESCE(ready_at, updated_at) <= ?', [$now->copy()->subDays($t['uncollected_days'])])
            ->whereRaw('COALESCE(ready_at, updated_at) >= ?', [$staleFrom])
            ->orderByRaw('COALESCE(ready_at, updated_at)')->limit(self::PER_GROUP)->get()
            ->map(fn ($o) => $this->orderItem($o, 'order_uncollected', 'Ready for ' . $o->daysAwaitingCollection() . ' days'));

        foreach ($this->stockItems($line)->groupBy('rule') as $rule => $items) {
            $groups[$rule] = $items->take(self::PER_GROUP)->values();
        }

        // Leave out what someone has dealt with or snoozed.
        $handled = $this->handled(collect($groups)->flatten(1));
        $result = [];
        foreach (self::RULES as $rule => [$title, $hint]) {
            $items = ($groups[$rule] ?? collect())->reject(fn ($item) => isset($handled[$this->key($item)]))->values();
            if ($items->isNotEmpty()) {
                if ($rule === 'stock_expiring') {
                    $title = 'Expiring within ' . $this->window($t['expiry_days']);
                }
                $result[$rule] = ['title' => $title, 'hint' => $hint, 'items' => $items];
            }
        }

        return $result;
    }

    /** Counts for menu badges and the start-of-shift summary, cached briefly per branch. */
    public function counts(string $line): array
    {
        return Cache::remember(TenantCache::key('attention-counts-' . $line, true), 120, function () use ($line) {
            $groups = $this->groups($line);
            $n = fn (string $rule) => isset($groups[$rule]) ? $groups[$rule]['items']->count() : 0;

            return [
                'appointments' => $n('appt_soon') + $n('appt_missed'),
                'appt_soon'    => $n('appt_soon'),
                'appt_missed'  => $n('appt_missed'),
                'order_due'    => $n('order_due'),
                'order_late'   => $n('order_late'),
                'uncollected'  => $n('order_uncollected'),
                // Badge: problems, not things merely coming up.
                'orders'       => $n('order_late') + $n('order_uncollected'),
                'stock_expired'  => $n('stock_expired'),
                'stock_expiring' => $n('stock_expiring_soon') + $n('stock_expiring'),
                'stale'        => $this->staleQuery($line)->count(),
                'total'        => array_sum(array_map(fn ($g) => $g['items']->count(), $groups)),
            ];
        });
    }

    /**
     * Orders too old to chase: late, or ready and not collected, for longer than the clinic's
     * "stop chasing" days. They wait here until someone marks them collected (tidyCollected).
     */
    public function staleOrders(string $line): Collection
    {
        return $this->staleQuery($line)->orderByRaw('COALESCE(pickUpDate, ready_at, created_at)')->get();
    }

    private function staleQuery(string $line)
    {
        $staleFrom = Carbon::today()->subDays(self::thresholds()['stale_days']);

        return $this->orders($line)->where(fn ($q) => $q
            ->where(fn ($late) => $this->open($late)->whereNotNull('pickUpDate')->whereDateIndexed('pickUpDate', '<', $staleFrom->toDateString()))
            ->orWhere(fn ($ready) => $ready->whereIn('status', LensOrder::READY)->whereRaw('COALESCE(ready_at, updated_at) < ?', [$staleFrom])));
    }

    /**
     * Old orders the patient collected before anyone recorded it. The collection date is set
     * to when the glasses were promised (or ready), so no aftercare or feedback message goes
     * out now as if they had just been collected. Returns how many were marked.
     *
     * @param  array<int>  $orderIds
     */
    public function tidyCollected(string $line, array $orderIds, ?string $note = null): int
    {
        $ids = array_map('intval', $orderIds);
        $stale = $this->staleOrders($line)->whereIn('id', $ids);
        $note = trim((string) $note) ?: null;
        $who = Auth::user()?->name ?? 'staff';

        DB::transaction(function () use ($stale, $note, $who) {
            foreach ($stale as $order) {
                $collectedAt = Carbon::parse($order->ready_at ?? $order->pickUpDate ?? $order->created_at);
                $old = $order->status;
                $order->update([
                    'status'       => 'Collected',
                    'collected_at' => $order->collected_at ?? $collectedAt,
                    'renewal_date' => $order->renewal_date ?? $collectedAt->copy()->addYear()->toDateString(),
                ]);
                // On the order's history, not its notes (optical jobs keep their details as JSON there).
                AuditTrail::record('spectacles.status_changed', "Old order marked collected in tidy-up by {$who}" . ($note ? " — {$note}" : ''),
                    $order, ['status' => $old], ['status' => 'Collected', 'tidy_up' => true, 'note' => $note], $order->patient_id ?? null);
            }
        });
        $this->forgetCounts();

        return $stale->count();
    }

    /**
     * Stock expiring within the clinic's window, or expired, with what is probably still on
     * the shelf. Batches do not track their own sales, so a batch's share of the branch's stock
     * is estimated first-expiry-first-out: the stock belongs to the latest batches first.
     */
    public function stockItems(string $line, ?Carbon $today = null): Collection
    {
        $t = self::thresholds();
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $until = $today->copy()->addDays($t['expiry_days']);
        $items = collect();

        if ($line === self::OPTICAL) {
            $lots = OpticalStockLot::with('product:id,name,sku,deleted_at')->where('quantity', '>', 0)->get();
            $stock = OpticalProductStock::whereIn('optical_product_id', $lots->pluck('optical_product_id')->unique())->pluck('quantity', 'optical_product_id');
            foreach ($this->estimate($lots, 'optical_product_id', $stock) as [$lot, $left]) {
                if ($lot->expiry_date->gt($until)) continue;
                $items->push($this->stockItem('optical_lot', $lot->id, $lot->product?->name ?? 'Optical item', $lot->batch_number, $lot->expiry_date, $left, route('optical.stock'), $today));
            }
        } else {
            $lots = InventoryLot::with('product:id,name,made_to_order')->where('quantity', '>', 0)->whereNotNull('expiry_date')->get()
                ->filter(fn ($lot) => $lot->product && ! $lot->product->made_to_order);
            $stock = $this->clinicStock($lots->pluck('product_id')->unique()->all());
            foreach ($this->estimate($lots, 'product_id', $stock) as [$lot, $left]) {
                if ($lot->expiry_date->gt($until)) continue;
                $items->push($this->stockItem('inventory_lot', $lot->id, $lot->product->name, $lot->batch_number, $lot->expiry_date, $left, $this->clinicStockUrl(), $today));
            }

            // Products with an expiry date but no batches (entered before batches were kept).
            $products = Product::where('made_to_order', false)->whereNotNull('expiry_date')
                ->whereDateIndexed('expiry_date', '<=', $until->toDateString())
                ->whereNotIn('id', InventoryLot::withoutGlobalScope('branch')->whereNotNull('expiry_date')->select('product_id'))
                ->get(['id', 'name', 'batch_number', 'expiry_date', 'quantity']);
            $stock = $this->clinicStock($products->pluck('id')->all(), $products);
            foreach ($products as $product) {
                $left = (int) ($stock[$product->id] ?? 0);
                if ($left <= 0) continue;
                $items->push($this->stockItem('product', $product->id, $product->name, $product->batch_number, Carbon::parse($product->expiry_date), $left, $this->clinicStockUrl(), $today));
            }
        }

        return $items->sortBy('expires')->values();
    }

    /**
     * Take a batch off the shelf: its remaining stock is removed as an expiry write-off and
     * recorded in the stock history. Super Admin only.
     */
    public function writeOff(string $type, int $id, ?string $note = null): int
    {
        abort_unless(Auth::user()?->hasRole('Super Admin'), 403);
        abort_unless(in_array($type, self::STOCK_TYPES, true), 422);
        $note = trim((string) $note) ?: null;
        $line = $type === 'optical_lot' ? self::OPTICAL : self::CLINIC;
        $item = $this->stockItems($line)->first(fn ($item) => $item['type'] === $type && $item['id'] === $id);
        $quantity = (int) ($item['quantity'] ?? 0);

        $subject = DB::transaction(function () use ($type, $id, $quantity, $note) {
            $reason = 'Expired stock removed' . ($note ? " — {$note}" : '');
            if ($type === 'optical_lot') {
                $lot = OpticalStockLot::with('product')->lockForUpdate()->findOrFail($id);
                if ($quantity > 0) {
                    app(OpticalStockLedgerService::class)->writeOffExpired($lot->product, $quantity,
                        trim(($lot->batch_number ? "Batch {$lot->batch_number}, " : '') . 'expiry ' . $lot->expiry_date->format('d M Y') . ($note ? " — {$note}" : '')));
                }
                $lot->update(['quantity' => 0]);

                return $lot;
            }

            $lot = $type === 'inventory_lot' ? InventoryLot::lockForUpdate()->findOrFail($id) : null;
            $product = Product::findOrFail($lot?->product_id ?? $id);
            if ($quantity > 0) {
                $inventory = app(BranchInventoryService::class);
                $before = $inventory->quantity($product);
                $inventory->decrease($product, $quantity);
                Stock::create([
                    'product_id' => $product->id, 'user_id' => Auth::id(),
                    'reference_no' => 'EXP-' . now()->format('Ymd-His') . '-' . random_int(100, 999),
                    'movement_type' => 'expired',
                    'batch_number' => $lot?->batch_number ?? $product->batch_number,
                    'quantity_before' => $before, 'quantity' => $quantity, 'quantity_after' => max(0, $before - $quantity),
                    'cost_price' => $lot?->unit_cost ?? $product->cost_price,
                    'expiry_date' => $lot?->expiry_date ?? $product->expiry_date,
                    'notes' => $reason,
                ]);
            }
            $lot?->update(['quantity' => 0]);

            return $lot ?? $product;
        });

        AttentionAction::create([
            'subject_type' => $type, 'subject_id' => $id, 'rule' => 'stock_expired', 'action' => AttentionAction::DONE,
            'note' => mb_substr("Removed {$quantity} from stock" . ($note ? " — {$note}" : ''), 0, 500), 'user_id' => Auth::id(),
        ]);
        AuditTrail::record('stock.expired_written_off', ($item['title'] ?? 'Stock') . ": {$quantity} removed from stock as expired" . ($note ? " — {$note}" : ''),
            $subject, [], ['quantity' => $quantity, 'note' => $note]);
        $this->forgetCounts();

        return $quantity;
    }

    /** Mark an item done (for good) or snooze it until tomorrow morning, with an optional note. */
    public function act(string $rule, int $subjectId, string $action, ?string $note = null, ?string $type = null): void
    {
        abort_unless(isset(self::RULES[$rule]) && in_array($action, [AttentionAction::DONE, AttentionAction::SNOOZED], true), 422);
        $type = self::RULES[$rule][2] === 'stock' ? $type : self::RULES[$rule][2];
        abort_unless($type && ($type !== 'stock' && in_array($type, ['appointment', 'lens_order', ...self::STOCK_TYPES], true)), 422);
        // Expired stock stays on the list until it is removed from stock.
        abort_if($rule === 'stock_expired' && $action === AttentionAction::DONE, 422);
        $subject = match ($type) {
            'appointment'   => Appointments::findOrFail($subjectId),
            'lens_order'    => LensOrder::findOrFail($subjectId),
            'inventory_lot' => InventoryLot::findOrFail($subjectId),
            'product'       => Product::findOrFail($subjectId),
            'optical_lot'   => OpticalStockLot::findOrFail($subjectId),
        };
        $note = trim((string) $note) ?: null;

        AttentionAction::create([
            'subject_type'  => $type,
            'subject_id'    => $subject->id,
            'rule'          => $rule,
            'action'        => $action,
            'snoozed_until' => $action === AttentionAction::SNOOZED ? Carbon::tomorrow()->setTime(7, 0) : null,
            'note'          => $note ? mb_substr($note, 0, 500) : null,
            'user_id'       => Auth::id(),
        ]);

        // The note goes on the order's, appointment's or batch's history.
        AuditTrail::record(
            'attention.' . $action,
            self::RULES[$rule][0] . ': ' . ($action === AttentionAction::DONE ? 'dealt with' : 'snoozed until tomorrow') . ($note ? " — {$note}" : ''),
            $subject, [], ['rule' => $rule, 'note' => $note], $subject->patient_id ?? null
        );

        $this->forgetCounts();
    }

    public function forgetCounts(): void
    {
        foreach ([self::CLINIC, self::OPTICAL] as $line) {
            Cache::forget(TenantCache::key('attention-counts-' . $line, true));
        }
    }

    private function orders(string $line)
    {
        return LensOrder::with(['patient:id,name,contact', 'refraction.consultation.patient:id,name,contact'])
            ->when($line === self::CLINIC, fn ($q) => $q->whereNotNull('refraction_id'))
            ->whereNull('cancelled_at');
    }

    private function open($query)
    {
        return $query->whereNotIn('status', [...LensOrder::READY, 'Collected', 'Cancelled', 'Quotation']);
    }

    /**
     * Each batch with its estimated quantity left, latest-expiring batches filled first.
     *
     * @return array<int, array{0: mixed, 1: int}>
     */
    private function estimate(Collection $lots, string $productKey, $stock): array
    {
        $result = [];
        foreach ($lots->groupBy($productKey) as $productId => $ofProduct) {
            $left = max(0, (int) ($stock[$productId] ?? 0));
            foreach ($ofProduct->sortByDesc(fn ($lot) => $lot->expiry_date->format('Ymd') . str_pad((string) $lot->id, 10, '0', STR_PAD_LEFT)) as $lot) {
                $share = min((int) $lot->quantity, $left);
                $left -= $share;
                if ($share > 0) {
                    $result[] = [$lot, $share];
                }
            }
        }

        return $result;
    }

    /** product id => quantity at this branch (older clinics without branch stock use the product's own count). */
    private function clinicStock(array $productIds, ?Collection $products = null): array
    {
        if (! $productIds) {
            return [];
        }
        $branch = BranchInventoryItem::whereIn('product_id', $productIds)->pluck('quantity', 'product_id')->all();
        $tracked = BranchInventoryItem::withoutGlobalScopes()->whereIn('product_id', $productIds)->distinct()->pluck('product_id')->flip();
        $products ??= Product::whereIn('id', $productIds)->get(['id', 'quantity']);
        foreach ($products as $product) {
            if (! isset($branch[$product->id]) && ! isset($tracked[$product->id])) {
                $branch[$product->id] = (int) $product->quantity;
            }
        }

        return $branch;
    }

    private function clinicStockUrl(): ?string
    {
        return Auth::user()?->hasAnyRole(['Super Admin', 'Manager']) ? route('admin.inventory-alerts') : null;
    }

    private function stockItem(string $type, int $id, string $name, ?string $batch, Carbon $expires, int $quantity, ?string $url, Carbon $today): array
    {
        $days = (int) $today->diffInDays($expires->copy()->startOfDay(), false);
        $rule = $days < 0 ? 'stock_expired' : ($days <= self::EXPIRY_SOON_DAYS ? 'stock_expiring_soon' : 'stock_expiring');
        $when = $days < 0 ? 'expired ' . $expires->format('j M Y')
            : 'expires ' . $expires->format('j M Y') . ($days === 0 ? ' (today)' : " (in {$days} " . ($days === 1 ? 'day' : 'days') . ')');

        return [
            'rule' => $rule, 'type' => $type, 'id' => $id,
            'title' => $name . ($batch ? ' · batch ' . $batch : ''),
            'detail' => "About {$quantity} left · {$when}",
            'phone' => null, 'url' => $url,
            'quantity' => $quantity, 'expires' => $expires->format('Y-m-d'),
        ];
    }

    private function window(int $days): string
    {
        return $days % 30 === 0 && $days >= 60 ? ($days / 30) . ' months' : $days . ' days';
    }

    /** "rule|type|id" => true for items done, or snoozed until later. */
    private function handled(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $handled = [];
        foreach ($items->groupBy('type') as $type => $ofType) {
            AttentionAction::where('subject_type', $type)
                ->whereIn('subject_id', $ofType->pluck('id')->unique())
                ->where(fn ($q) => $q->where('action', AttentionAction::DONE)
                    ->orWhere(fn ($s) => $s->where('action', AttentionAction::SNOOZED)->where('snoozed_until', '>', now())))
                ->get(['rule', 'subject_type', 'subject_id'])
                ->each(function ($action) use (&$handled) { $handled[$action->rule . '|' . $action->subject_type . '|' . $action->subject_id] = true; });
        }

        return $handled;
    }

    private function key(array $item): string
    {
        return $item['rule'] . '|' . $item['type'] . '|' . $item['id'];
    }

    private function appointmentItem(Appointments $a, string $rule, string $detail): array
    {
        return [
            'rule' => $rule, 'type' => 'appointment', 'id' => $a->id,
            'title' => $a->patient?->name ?? 'Patient',
            'detail' => $detail . ($a->title ? ' · ' . $a->title : '') . ($a->status !== 'Pending' ? ' · ' . $a->status : ''),
            'phone' => $a->patient?->contact,
            'url' => route('secretary.appointments'),
        ];
    }

    private function orderItem(LensOrder $o, string $rule, string $detail): array
    {
        return [
            'rule' => $rule, 'type' => 'lens_order', 'id' => $o->id,
            'title' => $o->display_customer_name . ' · ' . $o->order_id,
            'detail' => $detail,
            'phone' => $o->display_customer_phone,
            'url' => $o->refraction_id ? route('secretary.spectacles') : route('optical.orders'),
        ];
    }

    private function daysAgo(Carbon $date): string
    {
        $days = (int) $date->copy()->startOfDay()->diffInDays(Carbon::today());

        return $days . ' ' . ($days === 1 ? 'day' : 'days');
    }
}
