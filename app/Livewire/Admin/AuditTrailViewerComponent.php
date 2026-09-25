<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\AuditTrailArchive;
use App\Services\LicenseService;
use App\Support\Feature;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class AuditTrailViewerComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public bool $showArchive = false;
    public $search = '';
    public $event = '';
    public $userId = '';
    public $userSearch = '';
    public $fromDate;
    public $toDate;

    public function mount()
    {
        // Fix #12: generic 403 — don't reveal feature flag names
        abort_if(!LicenseService::has(Feature::AUDIT_TRAIL), 403);
        $this->fromDate = Carbon::today()->subDays(30)->toDateString();
        $this->toDate = Carbon::today()->toDateString();
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingEvent() { $this->resetPage(); }
    public function updatingUserId() { $this->resetPage(); }
    public function updatingUserSearch() { $this->resetPage(); }
    public function updatingFromDate() { $this->resetPage(); }
    public function updatingToDate() { $this->resetPage(); }

    public function toggleArchive(): void
    {
        $this->showArchive = !$this->showArchive;
        $this->search  = '';
        $this->event   = '';
        $this->userId  = '';
        $this->userSearch = '';
        $this->fromDate = $this->showArchive ? '' : Carbon::today()->subDays(30)->toDateString();
        $this->toDate   = $this->showArchive ? '' : Carbon::today()->toDateString();
        $this->resetPage();
    }

    public function resetFilters()
    {
        $this->search = '';
        $this->event = '';
        $this->userId = '';
        $this->userSearch = '';
        $this->mount();
        $this->resetPage();
    }

    public function exportCsv()
    {
        $query = $this->query();
        $filename = 'audit_trail_' . now()->format('Y-m-d_His') . '.csv';

        // Fix #2: sanitize values against CSV formula injection
        $sanitize = static function ($v): string {
            $v = (string) $v;
            return ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
        };

        $callback = function () use ($query, $sanitize) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Time', 'User', 'Patient', 'Event', 'Description', 'IP Address']);
            $query->chunkById(500, function ($chunk) use ($file, $sanitize) {
                foreach ($chunk as $event) {
                    fputcsv($file, array_map($sanitize, [
                        $event->created_at->format('Y-m-d H:i:s'),
                        $event->user->name ?? 'System',
                        $event->patient->name ?? '',
                        $event->event,
                        $event->description,
                        $event->ip_address,
                    ]));
                }
            });
            fclose($file);
        };

        return Response::streamDownload($callback, $filename, ['Content-Type' => 'text/csv']);
    }

    public function formatEventLabel(string $event): string
    {
        return Str::headline(str_replace('.', ' ', $event));
    }

    public function formatAuditChanges(Model $audit): array
    {
        $oldValues = is_array($audit->old_values) ? $audit->old_values : [];
        $newValues = is_array($audit->new_values) ? $audit->new_values : [];

        if (isset($newValues['new']) && is_array($newValues['new'])) {
            $newValues = $newValues['new'];
        }

        if (isset($oldValues['old']) && is_array($oldValues['old'])) {
            $oldValues = $oldValues['old'];
        }

        $changes = [];
        $flatNewValues = $this->flattenAuditValues($newValues);

        foreach ($flatNewValues as $key => $newValue) {
            if ($newValue === null || $newValue === '') {
                continue;
            }

            $oldValue = data_get($oldValues, $key);
            $label = $this->humanizeAuditKey($key);

            if ($oldValue !== null && $oldValue !== '' && $oldValue != $newValue) {
                $changes[] = $label . ': ' . $this->formatAuditValue($oldValue, $key) . ' to ' . $this->formatAuditValue($newValue, $key);
                continue;
            }

            $changes[] = $label . ': ' . $this->formatAuditValue($newValue, $key);
        }

        return array_slice($changes, 0, 8);
    }

    private function flattenAuditValues(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix ? $prefix . '.' . $key : (string) $key;

            if (is_array($value) && !$this->isListArray($value)) {
                $flat += $this->flattenAuditValues($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    private function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function humanizeAuditKey(string $key): string
    {
        $key = Str::afterLast($key, '.');
        return Str::headline(str_replace('_', ' ', $key));
    }

    private function formatAuditValue($value, string $key): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return collect($value)->map(fn ($item) => is_scalar($item) ? $item : json_encode($item))->join(', ');
        }

        if (is_numeric($value) && Str::contains($key, ['amount', 'total', 'price', 'cost', 'paid', 'balance'])) {
            return currency() . ' ' . number_format((float) $value, 2);
        }

        return (string) $value;
    }

    private function query()
    {
        $model = $this->showArchive ? new AuditTrailArchive : new AuditTrail;

        return $model::with(['user', 'patient'])
            ->when($this->search, function ($query) {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', $search)
                        ->orWhere('event', 'like', $search)
                        ->orWhere('ip_address', 'like', $search)
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $search))
                        ->orWhereHas('patient', fn ($pq) => $pq->where('name', 'like', $search));
                });
            })
            ->when($this->event, fn ($query) => $query->where('event', $this->event))
            ->when($this->userId, fn ($query) => $query->where('user_id', $this->userId))
            ->when($this->fromDate, fn ($query) => $query->where('created_at', '>=', Carbon::parse($this->fromDate)->startOfDay()))
            ->when($this->toDate, fn ($query) => $query->where('created_at', '<=', Carbon::parse($this->toDate)->endOfDay()))
            ->latest('created_at');
    }

    public function render()
    {
        $eventModel = $this->showArchive ? AuditTrailArchive::class : AuditTrail::class;

        return view('livewire.admin.audit-trail-viewer-component', [
            'audits'      => $this->query()->paginate(20),
            'users'       => User::when($this->userSearch, function ($query) {
                $query->where('name', 'like', '%' . $this->userSearch . '%');
            })->orderBy('name')->get(['id', 'name']),
            'events'      => $eventModel::select('event')->distinct()->orderBy('event')->pluck('event'),
            'showArchive' => $this->showArchive,
        ])->layout('layouts.admin.admin-layout');
    }
}
