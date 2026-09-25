<?php

namespace App\Livewire\Admin;

use App\Models\ReportDelivery;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SystemHealthStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class OfflineHealthDashboardComponent extends Component
{
    public function refreshChecks(): void
    {
        SystemHealthStatus::record('offline_health_dashboard', [
            'checked_by' => auth()->id(),
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Offline health checks refreshed.']);
    }

    public function render()
    {
        $settings = Setting::getSettings();

        return view('livewire.admin.offline-health-dashboard-component', [
            'cards' => [
                $this->schedulerStatus(),
                $this->backupStatus(),
                $this->mailStatus($settings),
                $this->reportOutboxStatus(),
                $this->communicationStatus('sms', 'SMS'),
                $this->communicationStatus('whatsapp', 'WhatsApp'),
            ],
            'recentReportDeliveries' => ReportDelivery::latest()->limit(6)->get(),
            'recentFailedMessages' => SmsLog::where('success', false)->latest()->limit(8)->get(),
            'lastDashboardCheck' => SystemHealthStatus::findByKey('offline_health_dashboard')?->checked_at,
        ])->layout('layouts.admin.admin-layout');
    }

    private function schedulerStatus(): array
    {
        $record = SystemHealthStatus::findByKey('scheduler');
        $last = $record?->checked_at;

        if (!$last) {
            return $this->card('Windows Task Scheduler', 'critical', 'No heartbeat yet', 'Run php artisan schedule:run through Windows Task Scheduler to activate background work.', 'fa-clock');
        }

        $minutes = $last->diffInMinutes(now());
        $state = $minutes <= 5 ? 'healthy' : ($minutes <= 15 ? 'warning' : 'critical');
        $detail = $state === 'healthy'
            ? 'Scheduler heartbeat is current.'
            : 'Scheduler heartbeat is stale. Check the Windows Task Scheduler task.';

        return $this->card('Windows Task Scheduler', $state, $last->diffForHumans(), $detail, 'fa-clock', [
            'Last scheduler run' => $last->format('d M Y, h:i A'),
        ]);
    }

    private function backupStatus(): array
    {
        try {
            $disk = Storage::disk('backups');
            $folder = config('backup.backup.name');
            $files = collect($disk->files($folder))
                ->filter(fn ($path) => str_ends_with(strtolower($path), '.zip'))
                ->map(fn ($path) => [
                    'path' => $path,
                    'name' => basename($path),
                    'size' => $disk->size($path),
                    'modified' => Carbon::createFromTimestamp($disk->lastModified($path)),
                ])
                ->sortByDesc('modified')
                ->values();
        } catch (\Throwable $e) {
            return $this->card('Backups', 'critical', 'Unable to read backup folder', $e->getMessage(), 'fa-database');
        }

        $latest = $files->first();
        if (!$latest) {
            return $this->card('Backups', 'critical', 'No backup found', 'No backup zip exists in storage/app/backups.', 'fa-database');
        }

        $hours = $latest['modified']->diffInHours(now());
        $state = $hours <= 24 ? 'healthy' : ($hours <= 48 ? 'warning' : 'critical');

        return $this->card('Backups', $state, $latest['modified']->diffForHumans(), 'Latest backup: ' . $latest['name'], 'fa-database', [
            'Last backup' => $latest['modified']->format('d M Y, h:i A'),
            'Backup count' => (string) $files->count(),
            'Latest size' => $this->humanFileSize((int) $latest['size']),
        ]);
    }

    private function mailStatus(Setting $settings): array
    {
        $latestSent = ReportDelivery::where('status', ReportDelivery::STATUS_SENT)->latest('sent_at')->first();
        $configured = !empty($settings->smtp_host) && !empty($settings->smtp_username);

        if (!$configured) {
            return $this->card('Email Service', 'critical', 'SMTP incomplete', 'Mail settings need SMTP host and username.', 'fa-envelope');
        }

        if (!$latestSent) {
            return $this->card('Email Service', 'warning', 'No successful report email yet', 'SMTP is configured, but no report delivery success has been recorded.', 'fa-envelope');
        }

        $days = $latestSent->sent_at?->diffInDays(now()) ?? 999;
        $state = $days <= 7 ? 'healthy' : 'warning';

        return $this->card('Email Service', $state, $latestSent->sent_at->diffForHumans(), 'Last successful report email was recorded.', 'fa-envelope', [
            'Last successful email' => $latestSent->sent_at->format('d M Y, h:i A'),
            'Subject' => $latestSent->subject,
        ]);
    }

    private function reportOutboxStatus(): array
    {
        $pending = ReportDelivery::where('status', ReportDelivery::STATUS_PENDING)->count();
        $failed = ReportDelivery::where('status', ReportDelivery::STATUS_FAILED)->count();
        $latest = ReportDelivery::latest('last_attempt_at')->first();

        $state = $failed > 0 ? 'critical' : ($pending > 0 ? 'warning' : 'healthy');
        $summary = $pending + $failed === 0 ? 'No pending delivery' : ($pending + $failed) . ' awaiting retry';

        return $this->card('Report Delivery Outbox', $state, $summary, 'Failed reports retry automatically when the scheduler runs and internet/mail is available.', 'fa-paper-plane', [
            'Pending outbox count' => (string) $pending,
            'Failed outbox count' => (string) $failed,
            'Last attempt' => $latest?->last_attempt_at?->format('d M Y, h:i A') ?? 'Never',
        ]);
    }

    private function communicationStatus(string $channel, string $label): array
    {
        $query = SmsLog::where('success', false);

        if ($channel === 'whatsapp') {
            $query->where('channel', 'whatsapp');
        } else {
            $query->where(function ($q) {
                $q->where('channel', 'sms')->orWhereNull('channel');
            });
        }

        $failedToday = (clone $query)->whereDate('created_at', today())->count();
        $failedTotal = (clone $query)->count();
        $latest = (clone $query)->latest()->first();
        $state = $failedToday === 0 ? 'healthy' : ($failedToday <= 5 ? 'warning' : 'critical');

        return $this->card($label . ' Delivery', $state, $failedToday . ' failed today', 'Shows failed local message logs so staff can resend or investigate.', $channel === 'whatsapp' ? 'fa-comments' : 'fa-sms', [
            'Failed today' => (string) $failedToday,
            'Failed total' => (string) $failedTotal,
            'Last failure' => $latest?->created_at?->format('d M Y, h:i A') ?? 'None',
        ]);
    }

    private function card(string $title, string $state, string $summary, string $detail, string $icon, array $metrics = []): array
    {
        return compact('title', 'state', 'summary', 'detail', 'icon', 'metrics');
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = max($bytes, 0);
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
    }
}
