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

    /** Filters on the Job Tracking list; each row can match several. */
    public const FILTERS = ['all' => 'All', 'late' => 'Late for customer', 'lab' => 'Late back from lab', 'stuck' => 'Stuck', 'holding' => 'Holding lenses'];

    /** Age groups, oldest first; very old jobs are usually abandoned or finished but never updated. */
    public const BUCKETS = ['old' => 'Over 30 days', 'week' => '8 to 30 days', 'recent' => 'Up to 7 days'];

    /**
     * Every open job that needs following up, once, with each reason it is listed: late for
     * the customer, late back from the lab, not moved for too long, holding stock lenses.
     * Worst first, by how many days the job is behind.
     *
     * @return Collection<int, array{order: LensOrder, late: ?array, stuck: ?int, held: Collection, severity: int, bucket: string, filters: array}>
     */
    public function attention(): Collection
    {
        $overdue = $this->overdue()->keyBy(fn ($row) => $row['order']->id);
        $stuck = $this->stuck()->keyBy(fn ($row) => $row['order']->id);

        return $overdue->keys()->merge($stuck->keys())->unique()->map(function ($id) use ($overdue, $stuck) {
            $late = $overdue->get($id);
            $still = $stuck->get($id);
            $order = ($late ?? $still)['order'];
            $held = $still['held'] ?? $order->lensLines->where('source', 'stock')->where('status', 'held')->values();
            $severity = max($late['days'] ?? 0, $still['days'] ?? 0);
            $labLate = $late && $order->status === 'Sent to Lab' && $order->expected_back_at?->lt(today());

            return [
                'order' => $order,
                'late' => $late ? ['reason' => $late['reason'], 'due' => $late['due'], 'days' => $late['days']] : null,
                'stuck' => $still['days'] ?? null,
                'held' => $held,
                'severity' => $severity,
                'bucket' => $severity > 30 ? 'old' : ($severity > 7 ? 'week' : 'recent'),
                'filters' => array_keys(array_filter([
                    'late' => $late && ! $labLate, 'lab' => $labLate, 'stuck' => (bool) $still, 'holding' => $held->isNotEmpty(),
                ])),
            ];
        })->sortByDesc('severity')->values();
    }

    /**
     * Promise the customer a new date (and, for a job at a lab, a new date back from the lab),
     * with the reason kept in the audit trail. The job stops counting as late.
     */
    public function reschedule(int $orderId, string $pickupDate, ?string $labDate, string $reason): LensOrder
    {
        app(ClinicAccessService::class)->assertWritable('optical');
        $reason = trim($reason);
        $errors = [];
        if (! strtotime($pickupDate) || \Illuminate\Support\Carbon::parse($pickupDate)->lt(today())) $errors['newPickupDate'] = 'Choose a new pickup date from today on.';
        if ($labDate && (! strtotime($labDate) || \Illuminate\Support\Carbon::parse($labDate)->lt(today()))) $errors['newLabDate'] = 'The date back from the lab cannot be in the past.';
        if ($labDate && strtotime($labDate) && strtotime($pickupDate) && \Illuminate\Support\Carbon::parse($labDate)->gt(\Illuminate\Support\Carbon::parse($pickupDate))) $errors['newLabDate'] = 'The job must be back from the lab by the pickup date.';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) $errors['rescheduleReason'] = 'Give the reason for the new date (at least 5 characters).';
        if ($errors) throw ValidationException::withMessages($errors);

        return DB::transaction(function () use ($orderId, $pickupDate, $labDate, $reason) {
            $order = LensOrder::lockForUpdate()->whereIn('status', self::OPEN)->findOrFail($orderId);
            $old = ['pickup_date' => $order->pickUpDate ? \Illuminate\Support\Carbon::parse($order->pickUpDate)->toDateString() : null, 'expected_back_at' => $order->expected_back_at?->toDateString()];
            $order->pickUpDate = \Illuminate\Support\Carbon::parse($pickupDate)->toDateString();
            if ($order->status === 'Sent to Lab' && $labDate) $order->expected_back_at = \Illuminate\Support\Carbon::parse($labDate)->toDateString();
            $order->save();
            AuditTrail::record('optical.order_rescheduled', "New date promised for {$order->order_id}: {$reason}", $order, $old,
                ['pickup_date' => $order->pickUpDate, 'expected_back_at' => $order->expected_back_at?->toDateString(), 'reason' => $reason], $order->patient_id, true);

            return $order;
        });
    }

    /** WhatsApp message telling the customer (or partner clinic) the glasses are delayed. */
    public function customerDelayLink(LensOrder $order): ?string
    {
        $recipient = app(OpticalCollectionNotifier::class)->recipient($order);
        if (! $recipient || ($recipient['notify_via'] ?? 'sms') === 'none') return null;
        $date = $order->pickUpDate && \Illuminate\Support\Carbon::parse($order->pickUpDate)->gte(today())
            ? ' We now expect them to be ready by '.\Illuminate\Support\Carbon::parse($order->pickUpDate)->format('l j F').'.'
            : ' We will let you know as soon as they are ready.';
        $whose = $order->isPartnerJob() ? 'the glasses for job '.$order->order_id.($order->customer_name ? ' ('.$order->customer_name.')' : '') : 'your glasses (order '.$order->order_id.')';

        return \App\Support\Messaging\WhatsAppLink::to($recipient['phone'] ?? null,
            'Hello '.$recipient['name'].', '.$whose.' are taking longer than planned.'.$date.' Sorry for the delay. '.$this->shopName());
    }

    /** WhatsApp message asking the lab when a job will be back. */
    public function labChaseLink(LensOrder $order): ?string
    {
        $lab = $order->labSupplier;
        if ($order->status !== 'Sent to Lab' || ! $lab) return null;
        $sent = $order->sent_to_lab_at ? ' sent to you on '.$order->sent_to_lab_at->format('j M') : '';
        $due = $order->expected_back_at ? ' and due back on '.$order->expected_back_at->format('j M') : '';

        return \App\Support\Messaging\WhatsAppLink::to($lab->phone,
            'Hello '.($lab->contact_person ?: $lab->name).', please can you update us on job '.$order->order_id.$sent.$due.'? When can we expect it back? '.$this->shopName());
    }

    private function shopName(): string
    {
        return '— '.(app(\App\Support\Tenancy\TenantContext::class)->clinic()?->name ?? 'the optical shop');
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

        // In-house: glazed in the shop, never sent out. Lab not recorded: sent out without naming the lab.
        return $jobs->groupBy(fn (LensOrder $order) => $order->lab_supplier_id ?: ($order->sent_to_lab_at ? 'unrecorded' : 'in-house'))->map(function (Collection $group, $key) {
            $first = $group->first();
            $median = function (Collection $values): ?float {
                $sorted = $values->sort()->values();
                $count = $sorted->count();
                if ($count === 0) return null;
                return round($count % 2 ? $sorted[intdiv($count, 2)] : ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2, 1);
            };
            $atLab = $group->filter(fn ($order) => $order->sent_to_lab_at);
            // On time: ready by the date due back from the lab, or else by the pickup date promised.
            $withDue = $group->filter(fn ($order) => $order->expected_back_at || $order->pickUpDate);
            $late = $withDue->filter(fn ($order) => $order->ready_at->copy()->startOfDay()->gt(\Illuminate\Support\Carbon::parse($order->expected_back_at ?? $order->pickUpDate)->startOfDay()));

            return [
                'lab' => $first->labSupplier?->name ?? match (true) { (bool) $first->lab_supplier_id => 'Removed lab', $key === 'unrecorded' => 'Lab not recorded', default => 'In-house' },
                'jobs' => $group->count(),
                'avg_days' => round($group->avg(fn ($order) => $order->created_at->diffInHours($order->ready_at) / 24), 1),
                'avg_lab_days' => $atLab->isEmpty() ? null : round($atLab->avg(fn ($order) => $order->sent_to_lab_at->diffInHours($order->ready_at) / 24), 1),
                // The median is the typical job: one forgotten order does not drag it out.
                'median_days' => $median($group->map(fn ($order) => $order->created_at->diffInHours($order->ready_at) / 24)),
                'median_lab_days' => $median($atLab->map(fn ($order) => $order->sent_to_lab_at->diffInHours($order->ready_at) / 24)),
                'on_time' => $withDue->isEmpty() ? null : round(($withDue->count() - $late->count()) / $withDue->count() * 100),
                'late' => $late->count(),
            ];
        })->sortByDesc('jobs')->values();
    }
}
