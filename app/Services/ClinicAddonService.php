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

    /** Features from the clinic's add-ons and its attendance package (when attendance is priced by staff size). @return list<string> */
    public function activeFeatures(int $clinicId): array
    {
        return $this->active[$clinicId] ??= array_values(array_unique([
            ...ClinicAddon::where('clinic_id', $clinicId)->activeAt()->pluck('feature')->all(),
            ...app(AttendanceSizing::class)->features($clinicId),
        ]));
    }

    /** Sold another way now: attendance itself goes by staff size (AttendanceSizing), which also replaces packs of extra staff. */
    public const NOT_SOLD = [\App\Support\Feature::ATTENDANCE, \App\Support\Feature::ATTENDANCE_STAFF_PACK];

    /** Extras this clinic's product can have, with the platform's price and whether clinics may ask for it. */
    public function catalogue(Clinic $clinic): Collection
    {
        $product = PlanProduct::productOf($this->subscription($clinic)?->feature_snapshot);
        $prices = PlatformAddon::all()->keyBy('feature');

        // Parts of attendance cost what the attendance price list says, and are offered unless the platform switched one off.
        $modules = \App\Support\Pricing\AttendanceSuitePrices::modules();

        return collect(PlanProduct::extrasFor($product))
            ->map(fn ($name, $feature) => [
                'feature' => $feature, 'name' => $name,
                'price' => (float) ($modules[$feature] ?? $prices[$feature]->monthly_price ?? 0),
                'currency' => $prices[$feature]->currency ?? $this->currency($clinic),
                'offered' => ! in_array($feature, self::NOT_SOLD, true)
                    && (bool) ($prices[$feature]->is_offered ?? isset($modules[$feature])),
                'pack_size' => in_array($feature, PlanProduct::ADDON_ONLY, true) ? (int) ($prices[$feature]->pack_size ?? PlanProduct::defaultPackSize($feature)) : null,
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
    public function add(Clinic $clinic, string $feature, float $monthlyPrice, ?string $reason, ?User $by, ?ClinicAddonRequest $request = null, int $quantity = 1): ClinicAddon
    {
        // Packs: the price is per pack, and the add-on holds the staff they give at today's pack size.
        $pack = $this->catalogue($clinic)->get($feature)['pack_size'] ?? null;
        $quantity = $pack ? max(1, min(1000, $quantity)) : 1;
        $monthlyPrice = round($monthlyPrice * $quantity, 2);
        $subscription = $this->subscription($clinic);
        if (! $subscription) throw ValidationException::withMessages(['addon' => 'This clinic has no subscription yet.']);
        if (! $this->catalogue($clinic)->has($feature)) throw ValidationException::withMessages(['addon' => "That add-on isn't available for this clinic's product."]);
        if (in_array($feature, self::NOT_SOLD, true)) throw ValidationException::withMessages(['addon' => $feature === \App\Support\Feature::ATTENDANCE
            ? 'Attendance is priced by staff size now: set it under "Attendance by staff size".' : 'Packs of extra staff are no longer sold: move the clinic up a staff size instead.']);
        if ($this->planIncludes($clinic, $feature)) throw ValidationException::withMessages(['addon' => 'The clinic\'s plan already includes this.']);
        if (in_array($feature, app(AttendanceSizing::class)->features($clinic->id), true)) throw ValidationException::withMessages(['addon' => 'The clinic\'s attendance package already includes this.']);
        if (in_array($feature, $this->activeFeatures($clinic->id), true)) throw ValidationException::withMessages(['addon' => 'The clinic already has this add-on.']);
        if ($monthlyPrice < 0) throw ValidationException::withMessages(['addon' => 'The price can\'t be negative.']);

        [$addon, $invoice] = DB::transaction(function () use ($clinic, $subscription, $feature, $monthlyPrice, $reason, $by, $request, $quantity, $pack) {
            $addon = ClinicAddon::create(['clinic_id' => $clinic->id, 'feature' => $feature, 'monthly_price' => round($monthlyPrice, 2), 'quantity' => $quantity, 'units' => $pack ? $pack * $quantity : null,
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

        // Clinical documents on a plan with no scan storage would have nowhere to keep the files: a storage pack comes with it.
        if ($feature === \App\Support\Feature::CLINICAL_DOCUMENTS && $this->needsStoragePack($clinic)) {
            $this->add($clinic, \App\Support\Feature::SCAN_STORAGE_PACK, (float) ($this->catalogue($clinic)->get(\App\Support\Feature::SCAN_STORAGE_PACK)['price'] ?? 0),
                'Comes with Clinical documents: the plan has no scan storage', $by);
        }

        return $addon;
    }

    /** The plan has no scan storage of its own and the clinic has no storage pack. */
    public function needsStoragePack(Clinic $clinic): bool
    {
        return $this->planStorageMb($clinic) === 0 && ! in_array(\App\Support\Feature::SCAN_STORAGE_PACK, $this->activeFeatures($clinic->id), true);
    }

    /** The scan storage the clinic's plan itself includes (null = no limit). */
    public function planStorageMb(Clinic $clinic): ?int
    {
        $limit = data_get($this->subscription($clinic)?->plan_snapshot, 'storage_limit_mb');

        return $limit === null ? null : (int) $limit;
    }

    /** Stop an add-on at the end of the period already paid for (at once if nothing is paid ahead). */
    public function cancel(ClinicAddon $addon, ?User $by, ?string $reason = null): ClinicAddon
    {
        // The storage pack is all the space Clinical documents has on a plan with no scan storage.
        if ($addon->feature === \App\Support\Feature::SCAN_STORAGE_PACK && $this->planStorageMb($addon->clinic) === 0
            && ClinicAddon::where('clinic_id', $addon->clinic_id)->where('feature', \App\Support\Feature::CLINICAL_DOCUMENTS)->activeAt()->whereNull('cancelled_at')->exists()) {
            throw ValidationException::withMessages(['addon' => 'Clinical documents keeps its files in this storage pack. Remove Clinical documents first, or move the clinic to a plan with scan storage.']);
        }
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

    /**
     * A different number of packs: the extra staff change now, the price (the same per pack) from
     * the next renewal, as with a price change.
     */
    public function changePacks(ClinicAddon $addon, int $packs): ClinicAddon
    {
        if ($packs < 1 || $packs > 1000) throw ValidationException::withMessages(['addon' => 'Choose between 1 and 1,000 packs.']);
        $quantity = max(1, (int) $addon->quantity);
        $perPack = (float) $addon->monthly_price / $quantity;
        $perPackStaff = (int) round(((int) $addon->units) / $quantity);
        $old = ['quantity' => $quantity, 'units' => $addon->units, 'monthly_price' => (float) $addon->monthly_price];
        $addon->update(['quantity' => $packs, 'units' => $perPackStaff * $packs]);
        $this->changePrice($addon, round($perPack * $packs, 2));
        app(PlatformAuditService::class)->record('CLINIC_ADDON_PACKS_CHANGED', $addon->clinic_id, $old, ['feature' => $addon->feature, 'quantity' => $packs, 'units' => $perPackStaff * $packs]);

        return $addon->fresh();
    }

    /** Extra staff on attendance from packs the clinic has now. */
    public function extraStaff(int $clinicId): int
    {
        return (int) ClinicAddon::where('clinic_id', $clinicId)->where('feature', \App\Support\Feature::ATTENDANCE_STAFF_PACK)->activeAt()->sum('units');
    }

    /** MB of scans & attachments the clinic's storage packs add to its plan. */
    public function extraStorageMb(int $clinicId): int
    {
        return 1024 * (int) ClinicAddon::where('clinic_id', $clinicId)->where('feature', \App\Support\Feature::SCAN_STORAGE_PACK)->activeAt()->sum('units');
    }

    /** Invoice lines for the add-ons in a renewal period: [description, amount, addon id]. */
    public function renewalLines(ClinicSubscription $subscription, CarbonInterface $start, CarbonInterface $end): array
    {
        return ClinicAddon::where('clinic_id', $subscription->clinic_id)->where('starts_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->where('monthly_price', '>', 0)->get()
            // Nothing for what the plan or the attendance package already gives.
            ->reject(fn (ClinicAddon $addon) => PlanProduct::allows($subscription->feature_snapshot, $addon->feature)
                || in_array($addon->feature, app(AttendanceSizing::class)->features($subscription->clinic_id), true))
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

    /** Charge the rest of the current period for something new at this monthly price (nothing on a trial or with no time left). */
    public function chargeRestOfPeriod(Clinic $clinic, float $monthlyPrice, string $label, string $notes): ?PlatformInvoice
    {
        $subscription = $this->subscription($clinic);
        if (! $subscription || ! ($charge = $this->prorata($clinic, $monthlyPrice))) return null;
        [$amount, $days] = $charge;

        return $this->invoice($clinic, $subscription, now()->startOfDay(), $subscription->current_period_ends_at,
            [[$label . " ({$days} " . Str::plural('day', $days) . ' to ' . $subscription->current_period_ends_at->format('j M Y') . ')', $amount, null]], $notes);
    }

    /**
     * Put a line in the next renewal invoice if it's already out, replacing any line that starts
     * with $prefix (null = just remove it). Once that invoice is (part) paid it stays as it is; a
     * new charge ($separately) then gets an invoice of its own.
     *
     * @param array{0: string, 1: float}|null $line the monthly amount; the period's amount is worked out here
     */
    public function syncUpcomingLine(ClinicSubscription $subscription, string $prefix, ?array $line, bool $separately = false): void
    {
        $invoice = $this->upcomingRenewal($subscription);
        if (! $invoice) return;
        $row = $line ? [$line[0], $this->periodPrice($subscription, (float) $line[1]), null] : null;
        if ((float) $invoice->amount_paid > 0 || $invoice->status !== 'unpaid') {
            if ($row && $separately) $this->invoice($subscription->clinic, $subscription, $invoice->period_start, $invoice->period_end, [$row], $line[0]);
            return;
        }
        $removed = $invoice->lines()->where('description', 'like', addcslashes($prefix, '%_') . '%')->delete();
        if ($row) $this->addLines($invoice, [$row]);
        elseif ($removed) $this->recalculate($invoice, $subscription);
    }

    /** A month (or a year, for yearly clinics) of this monthly price. */
    public function forPeriod(ClinicSubscription $subscription, float $monthlyPrice): float
    {
        return $this->periodPrice($subscription, $monthlyPrice);
    }

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
            ->whereNotIn('status', ['void', 'credited'])->whereDateIndexed('period_start', '>=', $from->copy()->subDay()->toDateString())
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

    /** Read the clinic's features afresh (after an add-on or its attendance size changes). */
    public function forget(int $clinicId): void
    {
        unset($this->active[$clinicId]);
    }
}
