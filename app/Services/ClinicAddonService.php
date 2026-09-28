<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicAddon;
use App\Models\ClinicAddonRequest;
use App\Models\ClinicSubscription;
use App\Models\PlatformAddon;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceLine;
use App\Models\User;
use App\Support\PlanProduct;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Paid add-ons: an extra feature for one clinic on top of its plan. What a clinic can use is its
 * plan's features plus its add-ons (SubscriptionService::access). Add-ons belong to the clinic,
 * so they survive renewals and plan changes; one the plan already includes isn't charged.
 *
 * Billing: prices are per month (yearly clinics pay twelve months). Adding one mid-period charges
 * the rest of the period pro rata straight away; clinics on a trial pay from their first renewal.
 * Renewal invoices list each add-on. Cancelling keeps it working to the end of the paid period.
 */
class ClinicAddonService
{
    /** Clinic id => active add-on features, for this request. */
    private array $active = [];

    /** @return list<string> */
    public function activeFeatures(int $clinicId): array
    {
        return $this->active[$clinicId] ??= ClinicAddon::where('clinic_id', $clinicId)->activeAt()->pluck('feature')->unique()->values()->all();
    }

    /** Extras this clinic's product can have, with the platform's price and whether clinics may ask for it. */
    public function catalogue(Clinic $clinic): Collection
    {
        $product = PlanProduct::productOf($this->subscription($clinic)?->feature_snapshot);
        $prices = PlatformAddon::all()->keyBy('feature');

        return collect(PlanProduct::EXTRAS)
            ->reject(fn ($extra) => $extra[1] === PlanProduct::CLINIC && $product === PlanProduct::OPTICAL)
            ->map(fn ($extra, $feature) => [
                'feature' => $feature, 'name' => $extra[0],
                'price' => (float) ($prices[$feature]->monthly_price ?? 0),
                'currency' => $prices[$feature]->currency ?? $this->currency($clinic),
                'offered' => (bool) ($prices[$feature]->is_offered ?? false),
            ]);
    }

    public function planIncludes(Clinic $clinic, string $feature): bool
    {
        $subscription = $this->subscription($clinic);

        return $subscription !== null && PlanProduct::allows($subscription->feature_snapshot, $feature);
    }

    /** What adding it now would charge straight away: [amount, days, period days], or null if nothing. */
    public function prorata(Clinic $clinic, float $monthlyPrice): ?array
    {
        $subscription = $this->subscription($clinic);
        if (! $subscription || $monthlyPrice <= 0 || ! in_array($subscription->status, ['active', 'overdue'], true)) return null;
        $start = $subscription->current_period_starts_at;
        $end = $subscription->current_period_ends_at;
        if (! $start || ! $end || $end->lte(now())) return null;

        $periodDays = max(1, (int) ceil($start->diffInSeconds($end) / 86400));
        $days = min($periodDays, max(1, (int) ceil(now()->startOfDay()->diffInSeconds($end) / 86400)));

        return [round($this->periodPrice($subscription, $monthlyPrice) * $days / $periodDays, 2), $days, $periodDays];
    }

    /**
     * Give the clinic an add-on now, charging the rest of the current period. The upcoming renewal
     * invoice gets it too if it's already been issued.
     */
    public function add(Clinic $clinic, string $feature, float $monthlyPrice, ?string $reason, ?User $by, ?ClinicAddonRequest $request = null): ClinicAddon
    {
        $subscription = $this->subscription($clinic);
        if (! $subscription) throw ValidationException::withMessages(['addon' => 'This clinic has no subscription yet.']);
        if (! $this->catalogue($clinic)->has($feature)) throw ValidationException::withMessages(['addon' => "That add-on isn't available for this clinic's product."]);
        if ($this->planIncludes($clinic, $feature)) throw ValidationException::withMessages(['addon' => 'The clinic\'s plan already includes this.']);
        if (in_array($feature, $this->activeFeatures($clinic->id), true)) throw ValidationException::withMessages(['addon' => 'The clinic already has this add-on.']);
        if ($monthlyPrice < 0) throw ValidationException::withMessages(['addon' => 'The price can\'t be negative.']);

        [$addon, $invoice] = DB::transaction(function () use ($clinic, $subscription, $feature, $monthlyPrice, $reason, $by, $request) {
            $addon = ClinicAddon::create(['clinic_id' => $clinic->id, 'feature' => $feature, 'monthly_price' => round($monthlyPrice, 2),
                'currency' => $this->currency($clinic), 'starts_at' => now(), 'reason' => $reason, 'added_by' => $by?->id, 'clinic_addon_request_id' => $request?->id]);

            $invoice = null;
            if ($charge = $this->prorata($clinic, $monthlyPrice)) {
                [$amount, $days] = $charge;
                $invoice = $this->invoice($clinic, $subscription, now()->startOfDay(), $subscription->current_period_ends_at,
                    [[$addon->name() . " add-on ({$days} " . Str::plural('day', $days) . ' to ' . $subscription->current_period_ends_at->format('j M Y') . ')', $amount, $addon->id]],
                    'Add-on: ' . $addon->name());
            }
            $this->addToUpcomingRenewal($subscription, $addon);
            $this->forget($clinic->id);

            return [$addon, $invoice];
        });

        app(PlatformAuditService::class)->record('CLINIC_ADDON_ADDED', $clinic->id, [], ['feature' => $feature, 'monthly_price' => $monthlyPrice, 'invoice' => $invoice?->number]);
        app(OwnerAlerts::class)->addonAdded($addon, $invoice);

        return $addon;
    }

    /** Stop an add-on at the end of the period already paid for (at once if nothing is paid ahead). */
    public function cancel(ClinicAddon $addon, ?User $by, ?string $reason = null): ClinicAddon
    {
        $subscription = $this->subscription($addon->clinic);
        $paidUntil = $subscription && in_array($subscription->status, ['active', 'overdue'], true) && $subscription->current_period_ends_at?->isFuture()
            ? $subscription->current_period_ends_at : now();

        DB::transaction(function () use ($addon, $by, $reason, $paidUntil, $subscription) {
            $addon->update(['ends_at' => $paidUntil, 'cancelled_at' => now(), 'cancelled_by' => $by?->id,
                'reason' => trim(($addon->reason ? $addon->reason . "\n" : '') . ($reason ? "Cancelled: {$reason}" : ''))]);
            if ($subscription) $this->removeFromUpcomingRenewal($subscription, $addon);
            $this->forget($addon->clinic_id);
        });
        app(PlatformAuditService::class)->record('CLINIC_ADDON_CANCELLED', $addon->clinic_id, [], ['feature' => $addon->feature, 'ends_at' => $paidUntil->toDateTimeString()]);
        app(OwnerAlerts::class)->addonCancelled($addon->fresh());

        return $addon->fresh();
    }

    /** New monthly price, charged from the next renewal. */
    public function changePrice(ClinicAddon $addon, float $monthlyPrice): ClinicAddon
    {
        if ($monthlyPrice < 0) throw ValidationException::withMessages(['addon' => 'The price can\'t be negative.']);
        DB::transaction(function () use ($addon, $monthlyPrice) {
            $old = (float) $addon->monthly_price;
            $addon->update(['monthly_price' => round($monthlyPrice, 2)]);
            if ($subscription = $this->subscription($addon->clinic)) {
                $this->removeFromUpcomingRenewal($subscription, $addon);
                $this->addToUpcomingRenewal($subscription, $addon);
            }
            app(PlatformAuditService::class)->record('CLINIC_ADDON_PRICE_CHANGED', $addon->clinic_id, ['monthly_price' => $old], ['feature' => $addon->feature, 'monthly_price' => $monthlyPrice]);
        });

        return $addon->fresh();
    }

    /** Invoice lines for the add-ons in a renewal period: [description, amount, addon id]. */
    public function renewalLines(ClinicSubscription $subscription, CarbonInterface $start, CarbonInterface $end): array
    {
        return ClinicAddon::where('clinic_id', $subscription->clinic_id)->where('starts_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->where('monthly_price', '>', 0)->get()
            ->reject(fn (ClinicAddon $addon) => PlanProduct::allows($subscription->feature_snapshot, $addon->feature))
            ->map(fn (ClinicAddon $addon) => [$addon->name() . ' add-on', $this->periodPrice($subscription, (float) $addon->monthly_price), $addon->id])
            ->values()->all();
    }

    // ── Clinic requests ──────────────────────────────────────────────────────

    public function request(Clinic $clinic, string $feature, ?string $message, ?User $by): ClinicAddonRequest
    {
        $offer = $this->catalogue($clinic)->get($feature);
        if (! $offer || ! $offer['offered']) throw ValidationException::withMessages(['addon' => 'That add-on isn\'t available.']);
        if ($this->planIncludes($clinic, $feature) || in_array($feature, $this->activeFeatures($clinic->id), true)) {
            throw ValidationException::withMessages(['addon' => 'You already have this.']);
        }
        if (ClinicAddonRequest::where('clinic_id', $clinic->id)->where('feature', $feature)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['addon' => 'You have already asked for this; it is waiting for the platform.']);
        }

        $request = ClinicAddonRequest::create(['clinic_id' => $clinic->id, 'feature' => $feature, 'message' => $message ?: null, 'status' => 'pending', 'requested_by' => $by?->id]);
        app(PlatformRequestAlerts::class)->addonRequested($request);

        return $request;
    }

    public function approve(ClinicAddonRequest $request, ?float $monthlyPrice, ?User $by): ClinicAddon
    {
        abort_unless($request->status === 'pending', 422, 'This request has already been answered.');
        $price = $monthlyPrice ?? (float) ($this->catalogue($request->clinic)->get($request->feature)['price'] ?? 0);

        return DB::transaction(function () use ($request, $price, $by) {
            $addon = $this->add($request->clinic, $request->feature, $price, 'Requested by the clinic', $by, $request);
            $request->update(['status' => 'approved', 'reviewed_by' => $by?->id, 'reviewed_at' => now()]);

            return $addon;
        });
    }

    public function reject(ClinicAddonRequest $request, ?string $notes, ?User $by): void
    {
        abort_unless($request->status === 'pending', 422, 'This request has already been answered.');
        $request->update(['status' => 'rejected', 'reviewed_by' => $by?->id, 'reviewed_at' => now(), 'review_notes' => $notes ?: null]);
        app(PlatformAuditService::class)->record('CLINIC_ADDON_REQUEST_REJECTED', $request->clinic_id, [], ['feature' => $request->feature]);
        app(OwnerAlerts::class)->addonRequestRejected($request);
    }

    // ── Invoices ─────────────────────────────────────────────────────────────

    private function addToUpcomingRenewal(ClinicSubscription $subscription, ClinicAddon $addon): void
    {
        $invoice = $this->upcomingRenewal($subscription);
        if (! $invoice || (float) $addon->monthly_price <= 0 || PlanProduct::allows($subscription->feature_snapshot, $addon->feature)) return;
        $line = [$addon->name() . ' add-on', $this->periodPrice($subscription, (float) $addon->monthly_price), $addon->id];

        // Not paid yet: the add-on joins that invoice. Already (part) paid: a separate invoice for it.
        if ((float) $invoice->amount_paid === 0.0 && $invoice->status === 'unpaid') {
            $this->addLines($invoice, [$line]);
        } else {
            $this->invoice($addon->clinic, $subscription, $invoice->period_start, $invoice->period_end, [$line], 'Add-on: ' . $addon->name());
        }
    }

    private function removeFromUpcomingRenewal(ClinicSubscription $subscription, ClinicAddon $addon): void
    {
        $invoice = $this->upcomingRenewal($subscription);
        if (! $invoice || (float) $invoice->amount_paid > 0 || $invoice->status !== 'unpaid') return;
        if ($addon->ends_at && $addon->ends_at->gt($invoice->period_start)) return;   // still works into that period
        if ($invoice->lines()->where('clinic_addon_id', $addon->id)->delete()) $this->recalculate($invoice, $subscription);
    }

    private function upcomingRenewal(ClinicSubscription $subscription): ?PlatformInvoice
    {
        $from = $subscription->current_period_ends_at ?? now();

        return PlatformInvoice::where('clinic_subscription_id', $subscription->id)->where('source', 'renewal')
            ->whereNotIn('status', ['void', 'credited'])->whereDate('period_start', '>=', $from->copy()->subDay()->toDateString())
            ->orderBy('period_start')->first();
    }

    /** @param list<array{0: string, 1: float, 2: ?int}> $lines */
    private function invoice(Clinic $clinic, ClinicSubscription $subscription, $start, $end, array $lines, string $notes): PlatformInvoice
    {
        $subtotal = round(array_sum(array_column($lines, 1)), 2);
        $tax = round($subtotal * $this->taxRate($subscription) / 100, 2);
        $invoice = PlatformInvoice::create([
            'number' => 'ADD-' . now()->format('YmHis') . '-' . Str::upper(Str::random(4)),
            'clinic_id' => $clinic->id, 'clinic_subscription_id' => $subscription->id,
            'period_start' => $start, 'period_end' => $end, 'due_date' => now()->addDays(7)->toDateString(),
            'subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax, 'amount_paid' => 0,
            'currency' => $this->currency($clinic), 'status' => 'unpaid', 'source' => 'addon', 'notes' => $notes,
        ]);
        foreach (array_values($lines) as $i => [$description, $amount, $addonId]) {
            $invoice->lines()->create(['description' => $description, 'amount' => $amount, 'clinic_addon_id' => $addonId, 'sort' => $i]);
        }

        return $invoice;
    }

    private function addLines(PlatformInvoice $invoice, array $lines): void
    {
        // Issued before invoices listed their charges: what it already charged becomes the first line.
        if (! $invoice->lines()->exists() && (float) $invoice->subtotal > 0) {
            $invoice->lines()->create(['description' => $invoice->notes ?: 'Subscription', 'amount' => $invoice->subtotal, 'sort' => 0]);
        }
        $sort = (int) $invoice->lines()->max('sort');
        foreach ($lines as [$description, $amount, $addonId]) {
            $invoice->lines()->create(['description' => $description, 'amount' => $amount, 'clinic_addon_id' => $addonId, 'sort' => ++$sort]);
        }
        $this->recalculate($invoice, $invoice->subscription);
    }

    /** Totals from the lines (an invoice made before lines existed keeps its subtotal as a first line). */
    private function recalculate(PlatformInvoice $invoice, ?ClinicSubscription $subscription): void
    {
        $subtotal = round((float) $invoice->lines()->sum('amount'), 2);
        $tax = round($subtotal * $this->taxRate($subscription) / 100, 2);
        $invoice->update(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);
    }

    private function periodPrice(ClinicSubscription $subscription, float $monthlyPrice): float
    {
        return round($subscription->billing_interval === 'yearly' ? $monthlyPrice * 12 : $monthlyPrice, 2);
    }

    private function taxRate(?ClinicSubscription $subscription): float
    {
        return (float) ($subscription?->pricing_snapshot['tax_rate'] ?? 0);
    }

    private function currency(Clinic $clinic): string
    {
        return $this->subscription($clinic)?->pricing_snapshot['currency'] ?? ($clinic->default_currency ?: 'GHS');
    }

    private function subscription(Clinic $clinic): ?ClinicSubscription
    {
        return app(SubscriptionService::class)->current($clinic);
    }

    private function forget(int $clinicId): void
    {
        unset($this->active[$clinicId]);
    }
}
