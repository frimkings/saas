<?php

namespace App\Livewire;

use App\Models\AttentionAction;
use App\Services\Reminders\AttentionItems;
use Livewire\Component;

/**
 * The "Needs attention" list for clinic or optical staff: on dashboards (compact, a few per
 * group) and as its own page. Each item can be called, opened, marked done or snoozed until
 * tomorrow, with an optional note; that clears it for the whole team. A Super Admin can take
 * expired stock off the books from here.
 */
class AttentionPanelComponent extends Component
{
    public string $line = AttentionItems::CLINIC;
    public bool $compact = false;
    public bool $page = false;

    /** The item being acted on: "rule|type|id|action" (action: done, snoozed, writeoff or release). */
    public ?string $acting = null;
    public string $note = '';

    private const COMPACT_PER_GROUP = 3;
    private const WRITE_OFF = 'writeoff';
    private const RELEASE = 'release';

    public function mount(?string $line = null, bool $compact = false): void
    {
        $this->page = $line === null;
        $this->line = $line ?? (request()->routeIs('optical.*') ? AttentionItems::OPTICAL : AttentionItems::CLINIC);
        $this->line = $this->line === AttentionItems::OPTICAL ? AttentionItems::OPTICAL : AttentionItems::CLINIC;
        $this->compact = $compact;
    }

    /** On the full page: items ticked for one action together, as "rule|type|id". */
    public array $ticked = [];
    public string $bulkNote = '';
    public ?string $bulkResult = null;

    /**
     * Mark done, snooze or release (held-lens jobs) every ticked item, with one optional note. Each
     * goes through the same rules as its own buttons; what doesn't apply is skipped.
     */
    public function bulk(string $action, AttentionItems $items): void
    {
        abort_unless(in_array($action, [AttentionAction::DONE, AttentionAction::SNOOZED, self::RELEASE], true), 422);
        $this->validate(['bulkNote' => 'nullable|string|max:500']);
        $done = 0;
        $skipped = [];
        foreach (array_slice(array_unique($this->ticked), 0, 100) as $key) {
            [$rule, $type, $id] = explode('|', (string) $key) + [null, null, null];
            if (! $rule || ! ctype_digit((string) $id)) continue;
            if ($action === self::RELEASE && $rule !== 'lens_held') { $skipped[] = 'not a held-lens job'; continue; }
            if ($action === AttentionAction::DONE && in_array($rule, ['stock_expired', 'lens_held'], true)) { $skipped[] = $rule === 'lens_held' ? 'held-lens jobs are released or snoozed' : 'expired stock is removed, not marked done'; continue; }
            try {
                $action === self::RELEASE
                    ? app(\App\Services\OpticalJobTrackingService::class)->releaseHeldLenses((int) $id, $this->bulkNote)
                    : $items->act((string) $rule, (int) $id, $action, $this->bulkNote, (string) $type);
                $done++;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $skipped[] = collect($e->errors())->flatten()->first();
            }
        }
        $items->forgetCounts();
        $this->ticked = [];
        $this->bulkNote = '';
        $verb = match ($action) { AttentionAction::DONE => 'marked done', AttentionAction::SNOOZED => 'snoozed until tomorrow', default => 'released' };
        $this->bulkResult = $done.' '.\Illuminate\Support\Str::plural('item', $done).' '.$verb.'.'
            .($skipped ? ' '.count($skipped).' skipped: '.implode('; ', array_unique($skipped)).'.' : '');
    }

    public function start(string $rule, string $type, int $id, string $action): void
    {
        $this->acting = "{$rule}|{$type}|{$id}|{$action}";
        $this->note = '';
    }

    public function cancel(): void
    {
        $this->acting = null;
        $this->note = '';
    }

    public function confirm(AttentionItems $items): void
    {
        if (!$this->acting) {
            return;
        }
        [$rule, $type, $id, $action] = explode('|', $this->acting) + [null, null, null, null];
        $this->validate(['note' => 'nullable|string|max:500']);

        if ($action === self::WRITE_OFF) {
            $removed = $items->writeOff((string) $type, (int) $id, $this->note);
            $message = $removed > 0 ? "Removed {$removed} from stock." : 'Cleared: none of this batch was left in stock.';
        } elseif ($action === self::RELEASE) {
            abort_unless($rule === 'lens_held', 422);
            $released = app(\App\Services\OpticalJobTrackingService::class)->releaseHeldLenses((int) $id, $this->note);
            $items->forgetCounts();
            $message = $released > 0
                ? $released.' '.\Illuminate\Support\Str::plural('lens', $released).' released for sale. The job takes a lens again at glazing if one is free.'
                : 'This job was no longer holding any lenses.';
        } else {
            $items->act((string) $rule, (int) $id, (string) $action, $this->note, (string) $type);
            $message = $action === AttentionAction::DONE ? 'Marked as dealt with.' : 'Snoozed until tomorrow.';
        }

        $this->dispatch('notify', ...['type' => 'success', 'message' => $message]);
        $this->cancel();
    }

    public function render(AttentionItems $items)
    {
        // Needs attention comes with Feature::NEEDS_ATTENTION; without it the panel is left out.
        if (!\App\Services\LicenseService::has(\App\Support\Feature::NEEDS_ATTENTION)) {
            return '<div></div>';
        }
        $groups = $items->groups($this->line);
        $total = array_sum(array_map(fn ($g) => $g['items']->count(), $groups));
        $optical = $this->line === AttentionItems::OPTICAL;
        $view = view('livewire.attention-panel', [
            'groups'   => $groups,
            'total'    => $total,
            'perGroup' => $this->compact ? self::COMPACT_PER_GROUP : null,
            'allUrl'   => $optical ? route('optical.attention') : route('attention'),
            'stale'    => $items->counts($this->line)['stale'],
            'staleDays' => AttentionItems::thresholds()['stale_days'],
            'tidyUrl'  => $optical ? route('optical.attention.old-orders') : route('attention.old-orders'),
            // Bench staff in the optical shop see the jobs, not the customer's phone.
            'canRelease' => $optical && \App\Support\Access\Access::can('optical.manage'),
            'contacts' => ! $optical || \App\Support\OpticalAccess::seesMoneyAndContacts(auth()->user()),
            'canWriteOff' => \App\Support\Access\Access::can('stock.write_off'),
        ]);

        return $this->page ? $view->layout(...self::layoutFor($this->line)) : $view;
    }

    /** The page frame that matches the person's own menu: [layout view, layout data]. */
    public static function layoutFor(string $line): array
    {
        if ($line === AttentionItems::OPTICAL) {
            return ['layouts.optical', []];
        }

        return \App\Support\ClinicNavigation::sharedLayout();
    }
}
