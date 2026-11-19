<?php

namespace App\Livewire\Optical\Concerns;

/**
 * Tick boxes for acting on several rows at once: the ticked ids, and a report of what the last
 * bulk action did (shown with the bulk-report partial). Each page decides its own actions, and
 * runs every row through the same checks as its single-row button.
 */
trait TicksRows
{
    /** @var array<int, string> ticked row ids */
    public array $selected = [];

    /** ['title', 'done' => [...], 'skipped' => [...], 'note' => ?, 'links' => [['url', 'label']]] */
    public ?array $bulkReport = null;

    public const BULK_LIMIT = 100;

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** @return array<int, int> the ticked ids, at most BULK_LIMIT */
    protected function tickedIds(): array
    {
        return collect($this->selected)->map(fn ($id) => (int) $id)->filter()->unique()->take(self::BULK_LIMIT)->values()->all();
    }

    /** Record the outcome; whatever was skipped stays ticked so it can be fixed and tried again. */
    protected function report(string $title, array $done, array $skipped, array $keepIds = [], ?string $note = null, array $links = []): void
    {
        $this->selected = array_values(array_map('strval', $keepIds));
        $this->bulkReport = ['title' => $title, 'done' => $done, 'skipped' => $skipped, 'note' => $note, 'links' => $links];
    }
}
