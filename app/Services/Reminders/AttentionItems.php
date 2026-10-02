<?php

namespace App\Services\Reminders;

use App\Models\Appointments;
use App\Models\AttentionAction;
use App\Models\AuditTrail;
use App\Models\LensOrder;
use App\Models\Setting;
use App\Support\Tenancy\TenantCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * "Needs attention" for clinic and optical staff: what to act on today, from data the
 * clinic already has. Everyone sees everything; marking an item done or snoozing it (with a
 * note) clears it for the whole team. Thresholds are the clinic's (Settings → Reminders).
 *
 *  - appt_soon:          appointments in the next few hours, patient not yet arrived
 *  - appt_missed:        today's appointments whose time has passed with no arrival
 *  - order_due:          spectacles promised today or within a few days, not yet ready
 *  - order_late:         spectacles past their promised date, not yet ready
 *  - order_uncollected:  glasses ready for a few days and not collected
 *
 * The clinic list covers the clinic's own spectacle orders (from a refraction); the optical
 * list covers every job on the optical shop's Orders page. Appointments are clinic only.
 */
class AttentionItems
{
    public const CLINIC = 'clinic';
    public const OPTICAL = 'optical';

    /** rule => [heading, what to do, subject type] */
    public const RULES = [
        'appt_soon'         => ['Appointments coming up', 'Make sure these patients are on their way.', 'appointment'],
        'appt_missed'       => ['Missed today', 'Call to find out, and rebook.', 'appointment'],
        'order_late'        => ['Spectacles past their promised date', 'Chase the lab and tell the patient the new date.', 'lens_order'],
        'order_due'         => ['Spectacles due soon', 'Check they will be ready on time.', 'lens_order'],
        'order_uncollected' => ['Ready, not collected', 'Call the patient to come in.', 'lens_order'],
    ];

    public const DEFAULTS = ['appointment_hours' => 2, 'due_days' => 1, 'uncollected_days' => 3, 'owner_uncollected_days' => 14];

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

        return [
            'appointment_hours'      => max(1, $pick('reminder_appointment_hours', 'appointment_hours', 24)),
            'due_days'               => $pick('reminder_due_days', 'due_days', 14),
            'uncollected_days'       => max(1, $pick('reminder_uncollected_days', 'uncollected_days', 90)),
            'owner_uncollected_days' => max(1, $pick('reminder_owner_uncollected_days', 'owner_uncollected_days', 365)),
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

        $orders = fn () => LensOrder::with(['patient:id,name,contact', 'refraction.consultation.patient:id,name,contact'])
            ->when($line === self::CLINIC, fn ($q) => $q->whereNotNull('refraction_id'))
            ->whereNull('cancelled_at');
        $open = fn ($q) => $q->whereNotIn('status', [...LensOrder::READY, 'Collected', 'Cancelled', 'Quotation']);

        $groups['order_late'] = $open($orders())->whereNotNull('pickUpDate')->whereDateIndexed('pickUpDate', '<', $today->toDateString())
            ->orderBy('pickUpDate')->limit(self::PER_GROUP)->get()
            ->map(fn ($o) => $this->orderItem($o, 'order_late', 'Promised ' . Carbon::parse($o->pickUpDate)->format('j M') . ' · ' . $this->daysAgo(Carbon::parse($o->pickUpDate)) . ' late · ' . $o->status));
        $groups['order_due'] = $open($orders())->whereNotNull('pickUpDate')
            ->whereDateIndexed('pickUpDate', '>=', $today->toDateString())
            ->whereDateIndexed('pickUpDate', '<=', $today->copy()->addDays($t['due_days'])->toDateString())
            ->orderBy('pickUpDate')->limit(self::PER_GROUP)->get()
            ->map(fn ($o) => $this->orderItem($o, 'order_due', 'Promised ' . (Carbon::parse($o->pickUpDate)->isToday() ? 'today' : Carbon::parse($o->pickUpDate)->format('D j M')) . ' · ' . $o->status));
        $groups['order_uncollected'] = $orders()->whereIn('status', LensOrder::READY)
            ->whereRaw('COALESCE(ready_at, updated_at) <= ?', [$now->copy()->subDays($t['uncollected_days'])])
            ->orderByRaw('COALESCE(ready_at, updated_at)')->limit(self::PER_GROUP)->get()
            ->map(fn ($o) => $this->orderItem($o, 'order_uncollected', 'Ready for ' . $o->daysAwaitingCollection() . ' days'));

        // Leave out what someone has dealt with or snoozed.
        $handled = $this->handled(collect($groups)->flatten(1));
        $result = [];
        foreach (self::RULES as $rule => [$title, $hint]) {
            $items = ($groups[$rule] ?? collect())->reject(fn ($item) => isset($handled[$this->key($item)]))->values();
            if ($items->isNotEmpty()) {
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
                'total'        => array_sum(array_map(fn ($g) => $g['items']->count(), $groups)),
            ];
        });
    }

    /** Mark an item done (for good) or snooze it until tomorrow morning, with an optional note. */
    public function act(string $rule, int $subjectId, string $action, ?string $note = null): void
    {
        abort_unless(isset(self::RULES[$rule]) && in_array($action, [AttentionAction::DONE, AttentionAction::SNOOZED], true), 422);
        $type = self::RULES[$rule][2];
        $subject = $type === 'appointment' ? Appointments::findOrFail($subjectId) : LensOrder::findOrFail($subjectId);
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

        // The note goes on the order's or appointment's history.
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

    /** "rule|id" => true for items done, or snoozed until later. */
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
                ->get(['rule', 'subject_id'])
                ->each(function ($action) use (&$handled) { $handled[$action->rule . '|' . $action->subject_id] = true; });
        }

        return $handled;
    }

    private function key(array $item): string
    {
        return $item['rule'] . '|' . $item['id'];
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
