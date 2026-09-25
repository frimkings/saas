<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\LensOrder;
use App\Models\OpticalOrderLensLine;
use App\Models\OpticalSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where optical jobs are and how long they take: jobs past their promised date, jobs that
 * have not moved for too long (and the stock lenses they are holding), and turnaround per
 * lab. "In-house" is a job glazed in the shop rather than sent to an outside lab.
 */
class OpticalJobTrackingService
{
    public const OPEN = ['Pending', 'Sent to Lab', 'In Production'];

    private function openJobs()
    {
        return LensOrder::with(['patient', 'partnerClinic', 'labSupplier', 'lensLines'])->whereIn('status', self::OPEN);
    }

    /**
     * Jobs not ready by the date promised to the customer, or not back from the lab when expected.
     *
     * @return Collection<int, array{order: LensOrder, reason: string, due: CarbonInterface, days: int}>
     */
    public function overdue(): Collection
    {
        return $this->openJobs()->get()->map(function (LensOrder $order) {
            $labLate = $order->status === 'Sent to Lab' && $order->expected_back_at?->lt(today());
            $pickupLate = $order->pickUpDate && \Illuminate\Support\Carbon::parse($order->pickUpDate)->lt(today());
            if (! $labLate && ! $pickupLate) return null;
            $due = $labLate ? $order->expected_back_at : \Illuminate\Support\Carbon::parse($order->pickUpDate);

            return [
                'order' => $order,
                'reason' => $labLate ? 'Not back from '.($order->labSupplier?->name ?? 'the lab') : 'Past the pickup date promised',
                'due' => $due,
                'days' => (int) $due->copy()->startOfDay()->diffInDays(today()),
            ];
        })->filter()->sortByDesc('days')->values();
    }

    /**
     * Open jobs whose status has not changed for the shop's "stuck" number of days, with
     * the stock lenses each is holding (those cannot be sold while held).
     *
     * @return Collection<int, array{order: LensOrder, days: int, held: Collection}>
     */
    public function stuck(): Collection
    {
        $cutoff = now()->subDays(OpticalSetting::stuckJobDays());

        return $this->openJobs()->where(fn ($query) => $query->where('status_changed_at', '<', $cutoff)
            ->orWhere(fn ($old) => $old->whereNull('status_changed_at')->where('updated_at', '<', $cutoff)))
            ->get()
            ->map(fn (LensOrder $order) => [
                'order' => $order,
                'days' => (int) ($order->status_changed_at ?? $order->updated_at)->copy()->startOfDay()->diffInDays(today()),
                'held' => $order->lensLines->where('source', 'stock')->where('status', 'held')->values(),
            ])
            ->sortByDesc('days')->values();
    }

    /**
     * Free the stock lenses a stuck job is holding so they can be sold. The job keeps its
     * plan: when it goes to glazing it takes a lens again if one is free.
     */
    public function releaseHeldLenses(int $orderId): int
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'Only a manager can release lenses held for a job.');

        return DB::transaction(function () use ($orderId) {
            $order = LensOrder::lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'Pending') {
                throw ValidationException::withMessages(['release' => 'Only jobs not yet glazed are holding lenses.']);
            }
            $released = OpticalOrderLensLine::where('lens_order_id', $order->id)->where('source', 'stock')->where('status', 'held')
                ->update(['status' => 'released']);
            if ($released > 0) {
                AuditTrail::record('optical.lenses_released', "Released {$released} held lens(es) from stuck job {$order->order_id}", $order, force: true);
            }

            return $released;
        });
    }

    /**
     * Turnaround for jobs that became ready in the period, per lab.
     *
     * @return Collection<int, array{lab: string, jobs: int, avg_days: float, avg_lab_days: ?float, on_time: ?float, late: int}>
     */
    public function turnaround(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $jobs = LensOrder::with('labSupplier')->whereNotNull('ready_at')
            ->whereBetween('ready_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();

        return $jobs->groupBy(fn (LensOrder $order) => $order->lab_supplier_id ?: 0)->map(function (Collection $group) {
            $first = $group->first();
            $atLab = $group->filter(fn ($order) => $order->sent_to_lab_at);
            // On time: ready by the date due back from the lab, or else by the pickup date promised.
            $withDue = $group->filter(fn ($order) => $order->expected_back_at || $order->pickUpDate);
            $late = $withDue->filter(fn ($order) => $order->ready_at->copy()->startOfDay()->gt(\Illuminate\Support\Carbon::parse($order->expected_back_at ?? $order->pickUpDate)->startOfDay()));

            return [
                'lab' => $first->labSupplier?->name ?? ($first->lab_supplier_id ? 'Removed lab' : 'In-house / not recorded'),
                'jobs' => $group->count(),
                'avg_days' => round($group->avg(fn ($order) => $order->created_at->diffInHours($order->ready_at) / 24), 1),
                'avg_lab_days' => $atLab->isEmpty() ? null : round($atLab->avg(fn ($order) => $order->sent_to_lab_at->diffInHours($order->ready_at) / 24), 1),
                'on_time' => $withDue->isEmpty() ? null : round(($withDue->count() - $late->count()) / $withDue->count() * 100),
                'late' => $late->count(),
            ];
        })->sortByDesc('jobs')->values();
    }
}
