<?php

namespace App\Livewire\Admin;

use App\Models\LoginLog;
use App\Models\LoginLogArchive;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;
use Livewire\Component;
use Livewire\WithPagination;

class LoginHistoryComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public bool $showArchive = false;
    public $search = '';
    public $userId = '';
    public $fromDate;
    public $toDate;

    public function mount()
    {
        $this->fromDate = '';
        $this->toDate = '';
        $this->recordCurrentSessionIfMissing();
    }

    private function recordCurrentSessionIfMissing(): void
    {
        $user = auth()->user();

        if (!$user || session()->has('login_history_recorded')) {
            return;
        }

        $recentLogExists = LoginLog::where('user_id', $user->id)
            ->where('ip_address', request()->ip())
            ->where('user_agent', request()->userAgent())
            ->where('login_at', '>=', now()->subMinutes(30))
            ->exists();

        if (!$recentLogExists) {
            LoginLog::recordFor($user);
        }

        session()->put('login_history_recorded', true);
    }

    public function updatingSearch() { $this->resetPage(); }
    public function updatingUserId() { $this->resetPage(); }
    public function updatingFromDate() { $this->resetPage(); }
    public function updatingToDate() { $this->resetPage(); }

    public function toggleArchive(): void
    {
        $this->showArchive = !$this->showArchive;
        $this->search   = '';
        $this->userId   = '';
        $this->fromDate = '';
        $this->toDate   = '';
        $this->resetPage();
    }

    public function resetFilters()
    {
        $this->search = '';
        $this->userId = '';
        $this->mount();
        $this->resetPage();
    }

    public function exportCsv()
    {
        $query    = $this->query();
        $filename = 'login_history_' . ($this->showArchive ? 'archive_' : '') . now()->format('Y-m-d_His') . '.csv';

        $callback = function () use ($query) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['User', 'Email', 'IP Address', 'Browser / Device', 'Login At']);
            $query->chunkById(500, function ($logs) use ($file) {
                foreach ($logs as $log) {
                    fputcsv($file, [
                        $log->user->name ?? 'Unknown',
                        $log->user->email ?? '',
                        $log->ip_address,
                        $log->user_agent,
                        optional($log->login_at)->format('Y-m-d H:i:s'),
                    ]);
                }
            });
            fclose($file);
        };

        return Response::streamDownload($callback, $filename, ['Content-Type' => 'text/csv']);
    }

    private function query()
    {
        $model = $this->showArchive ? new LoginLogArchive : new LoginLog;

        return $model::with('user')
            ->when($this->search, function ($query) {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('ip_address', 'like', $search)
                        ->orWhere('user_agent', 'like', $search)
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', $search)
                                ->orWhere('email', 'like', $search);
                        });
                });
            })
            ->when($this->userId, fn ($query) => $query->where('user_id', $this->userId))
            ->when($this->fromDate, fn ($query) => $query->where('login_at', '>=', Carbon::parse($this->fromDate)->startOfDay()))
            ->when($this->toDate, fn ($query) => $query->where('login_at', '<=', Carbon::parse($this->toDate)->endOfDay()))
            ->latest('login_at');
    }

    public function render()
    {
        return view('livewire.admin.login-history-component', [
            'logs'        => $this->query()->paginate(20),
            'users'       => User::orderBy('name')->get(['id', 'name', 'email']),
            'showArchive' => $this->showArchive,
        ])->layout('layouts.admin.admin-layout');
    }
}
