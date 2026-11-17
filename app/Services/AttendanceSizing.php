<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicAttendanceSize;
use App\Models\ClinicSubscription;
use App\Models\User;
use App\Support\Feature;
use App\Support\Pricing\AttendanceSuitePrices;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance priced by staff size, for an attendance-only clinic or one adding attendance to its
 * clinic or optical plan. The platform picks a package and a size; the clinic then has attendance
 * with that package's modules, may follow that many staff, and pays the size's price each month
 * (twelve months a year) on its renewal invoices.
 *
 * The price is the list's on the day it was set and stays when the list changes, until the
 * platform reprices it. From the special-pricing number of staff the platform types the agreed
 * price. Starting mid-period charges the rest of the period; a change of package or size works at
 * once and costs the new price from the next renewal; stopping keeps it to the end of the paid period.
 */
class AttendanceSizing
{
    /** The clinic's attendance size working now, if any. */
    public function current(int $clinicId): ?ClinicAttendanceSize
    {
        return ClinicAttendanceSize::where('clinic_id', $clinicId)->activeAt()->latest('starts_at')->first();
    }

    /**
     * What the size gives: attendance and its package's modules, or nothing. The "parts chosen"
     * marker keeps an older plan list (which would mean every part) to the package's modules.
     */
    public function features(int $clinicId): array
    {
        $size = $this->current($clinicId);

        return $size ? [Feature::ATTENDANCE, Feature::ATTENDANCE_PARTS_CHOSEN, ...AttendanceSuitePrices::modulesIn($size->package)] : [];
    }

    /** The list price for a package at this many staff: [special, size (the staff limit), monthly]. */
    public function listPrice(string $package, int $staff): array
    {
        $quote = AttendanceSuitePrices::quote($staff, [], $package);

        return ['special' => $quote['special'], 'size' => $quote['special'] ? max(1, $staff) : $quote['size'], 'monthly' => $quote['monthly']];
    }

    /**
     * Start attendance by size, or change the package, the size or the price of the one working.
     * $price null = the list's price (required from special pricing).
     */
    public function set(Clinic $clinic, string $package, int $staff, ?float $price, ?string $reason, ?User $by): ClinicAttendanceSize
    {
        if (! isset(AttendanceSuitePrices::PACKAGES[$package])) throw ValidationException::withMessages(['size' => 'Choose a package.']);
        if ($staff < 1 || $staff > 100000) throw ValidationException::withMessages(['size' => 'Enter a number of staff.']);
        if ($price !== null && $price < 0) throw ValidationException::withMessages(['size' => 'The price can\'t be negative.']);
        $subscription = app(SubscriptionService::class)->current($clinic);
        if (! $subscription) throw ValidationException::withMessages(['size' => 'This clinic has no subscription yet.']);
        $list = $this->listPrice($package, $staff);
        if ($list['special'] && $price === null) {
            throw ValidationException::withMessages(['size' => 'From ' . number_format(AttendanceSuitePrices::specialFrom()) . ' staff there is no list price: enter the monthly price you agreed.']);
        }
        $monthly = round($price ?? $list['monthly'], 2);
        $values = ['package' => $package, 'staff_limit' => $list['size'], 'monthly_price' => $monthly,
            'custom_price' => $list['special'] || abs($monthly - $list['monthly']) > 0.004];

        $current = $this->current($clinic->id);
        $old = $current?->only(['package', 'staff_limit', 'monthly_price']) ?? [];
        [$size, $invoice] = DB::transaction(function () use ($clinic, $subscription, $current, $values, $reason, $by) {
            if ($current) {
                $current->update($values + ['updated_by' => $by?->id, 'reason' => $this->note($current->reason, $reason)]);
                $this->syncRenewal($subscription, $current);

                return [$current, null];
            }
            $size = ClinicAttendanceSize::create($values + ['clinic_id' => $clinic->id, 'currency' => $subscription->pricing_snapshot['currency'] ?? 'GHS',
                'starts_at' => now(), 'reason' => $reason, 'added_by' => $by?->id]);
            $invoice = app(ClinicAddonService::class)->chargeRestOfPeriod($clinic, (float) $size->monthly_price, $this->label($size), 'Attendance Suite: ' . $size->name());
            $this->syncRenewal($subscription, $size, separately: true);

            return [$size, $invoice];
        });

        $this->endCoveredAddons($clinic->id, $size->package, $by);

        app(PlatformAuditService::class)->record($current ? 'CLINIC_ATTENDANCE_SIZE_CHANGED' : 'CLINIC_ATTENDANCE_SIZE_ADDED', $clinic->id,
            $old, ['package' => $size->package, 'staff_limit' => $size->staff_limit, 'monthly_price' => (float) $size->monthly_price,
                'invoice' => $invoice?->number]);
        app(ClinicAddonService::class)->forget($clinic->id);
        app(SubscriptionService::class)->forgetCurrent();

        return $size->fresh();
    }

    /**
     * Attendance modules bought one by one before the package that includes them: they stop now, so they are
     * not paid for twice. What an add-on charged for days the package now covers comes off its invoice (a
     * credit, or a void when it is all covered); days before the package stay owed. Returns the add-ons ended.
     */
    public function endCoveredAddons(int $clinicId, string $package, ?User $by = null, ?CarbonInterface $from = null): int
    {
        $from ??= now();
        $modules = AttendanceSuitePrices::modulesIn($package);
        $adjust = app(BillingAdjustmentService::class);
        $ended = 0;
        $addons = \App\Models\ClinicAddon::where('clinic_id', $clinicId)->whereIn('feature', $modules)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $from))->get();
        foreach ($addons as $addon) {
            DB::transaction(function () use ($addon, $from, $by, $adjust, $package) {
                $addon->update(['ends_at' => $from, 'cancelled_at' => $addon->cancelled_at ?? now(), 'cancelled_by' => $addon->cancelled_by ?? $by?->id,
                    'reason' => trim(($addon->reason ? $addon->reason . "
" : '') . 'Ended: included in the attendance ' . (AttendanceSuitePrices::PACKAGES[$package]['name'] ?? $package) . ' package.')]);
                $lines = \App\Models\PlatformInvoiceLine::with('invoice')->where('clinic_addon_id', $addon->id)->get();
                foreach ($lines as $line) {
                    $invoice = $line->invoice;
                    if (! $invoice || in_array($invoice->status, ['void', 'credited'], true) || ! $invoice->period_start || ! $invoice->period_end) continue;
                    $days = max(1, $invoice->period_start->diffInDays($invoice->period_end) + 1);
                    $covered = max(0, min($days, $from->copy()->startOfDay()->diffInDays($invoice->period_end, false) + 1));
                    if ($covered <= 0) continue;
                    $refund = round((float) $line->amount * $covered / $days, 2);
                    $reason = "{$covered} of {$days} days of {$line->description} are in the attendance package from " . $from->format('d M Y') . '.';
                    if ($invoice->lines()->count() === 1 && $covered >= $days && (float) $invoice->amount_paid <= 0 && (float) $invoice->credited_amount <= 0) {
                        $adjust->void($invoice, $reason, $by?->id);
                    } elseif ($invoice->balance() > 0) {
                        $adjust->credit($invoice, min($refund, $invoice->balance()), $reason, $by?->id);
                    }
                }
                $subscription = app(SubscriptionService::class)->current($addon->clinic);
                if ($subscription) app(ClinicAddonService::class)->removeFromUpcomingRenewal($subscription, $addon);
            });
            app(PlatformAuditService::class)->record('CLINIC_ADDON_CANCELLED', $clinicId, [], ['feature' => $addon->feature, 'ends_at' => $from->toDateTimeString(), 'reason' => 'included in attendance package']);
            $ended++;
        }
        if ($ended) app(ClinicAddonService::class)->forget($clinicId);

        return $ended;
    }

    /** Back to the list price for its package and size (not from special pricing, which has none). */
    public function reprice(ClinicAttendanceSize $size, ?User $by): ClinicAttendanceSize
    {
        $list = $this->listPrice($size->package, $size->staff_limit);
        if ($list['special']) throw ValidationException::withMessages(['size' => 'Special pricing has no list price to go back to.']);

        return $this->set($size->clinic, $size->package, $size->staff_limit, null, 'Repriced to the price list', $by);
    }

    /** Stop at the end of the period already paid for (at once if nothing is paid ahead). */
    public function stop(ClinicAttendanceSize $size, ?User $by, ?string $reason = null): ClinicAttendanceSize
    {
        $subscription = app(SubscriptionService::class)->current($size->clinic);
        $paidUntil = $subscription && in_array($subscription->status, ['active', 'overdue'], true) && $subscription->current_period_ends_at?->isFuture()
            ? $subscription->current_period_ends_at : now();
        DB::transaction(function () use ($size, $by, $reason, $paidUntil, $subscription) {
            $size->update(['ends_at' => $paidUntil, 'cancelled_at' => now(), 'updated_by' => $by?->id, 'reason' => $this->note($size->reason, $reason ? "Stopped: {$reason}" : 'Stopped')]);
            if ($subscription) app(ClinicAddonService::class)->syncUpcomingLine($subscription, ClinicAttendanceSize::LINE_PREFIX, null);
        });
        app(PlatformAuditService::class)->record('CLINIC_ATTENDANCE_SIZE_STOPPED', $size->clinic_id, [], ['ends_at' => $paidUntil->toDateTimeString()]);
        app(ClinicAddonService::class)->forget($size->clinic_id);
        app(SubscriptionService::class)->forgetCurrent();

        return $size->fresh();
    }

    /** The renewal invoice line for a period: [description, amount, null], or null if attendance isn't sold by size then. */
    public function renewalLine(ClinicSubscription $subscription, CarbonInterface $start, CarbonInterface $end): ?array
    {
        $size = ClinicAttendanceSize::where('clinic_id', $subscription->clinic_id)->where('starts_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->latest('starts_at')->first();

        return $size ? [$this->label($size), app(ClinicAddonService::class)->forPeriod($subscription, (float) $size->monthly_price), null] : null;
    }

    public function label(ClinicAttendanceSize $size): string
    {
        return ClinicAttendanceSize::LINE_PREFIX . $size->name();
    }

    private function syncRenewal(ClinicSubscription $subscription, ClinicAttendanceSize $size, bool $separately = false): void
    {
        app(ClinicAddonService::class)->syncUpcomingLine($subscription, ClinicAttendanceSize::LINE_PREFIX, [$this->label($size), (float) $size->monthly_price], $separately);
    }

    private function note(?string $old, ?string $new): ?string
    {
        return trim(($old ? $old . "\n" : '') . ($new ?? '')) ?: null;
    }
}
