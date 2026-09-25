<?php

namespace App\Livewire\Optical;

use App\Models\OpticalSetting;
use App\Services\OpticalJobTrackingService;
use Illuminate\Support\Carbon;
use Livewire\Component;

/** Job tracking: overdue jobs, stuck jobs (and the lenses they hold), and turnaround per lab. */
class OpticalJobsComponent extends Component
{
    public string $from = '';
    public string $to = '';

    protected $queryString = ['from', 'to'];

    public function mount(): void
    {
        $this->from = $this->validDate($this->from) ?? now()->subDays(89)->toDateString();
        $this->to = $this->validDate($this->to) ?? now()->toDateString();
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }

    public function releaseLenses(int $orderId): void
    {
        $released = app(OpticalJobTrackingService::class)->releaseHeldLenses($orderId);
        session()->flash('success', $released > 0
            ? "{$released} held ".\Illuminate\Support\Str::plural('lens', $released).' released for sale. The job takes a lens again at glazing if one is free.'
            : 'This job was not holding any lenses.');
    }

    public function render()
    {
        $service = app(OpticalJobTrackingService::class);
        $from = Carbon::parse($this->validDate($this->from) ?? now()->subDays(89)->toDateString());
        $to = Carbon::parse($this->validDate($this->to) ?? now()->toDateString());
        if ($from->gt($to)) [$from, $to] = [$to, $from];

        return view('livewire.optical.optical-jobs-component', [
            'overdue' => $service->overdue(),
            'stuck' => $service->stuck(),
            'turnaround' => $service->turnaround($from, $to),
            'stuckDays' => OpticalSetting::stuckJobDays(),
            'isManager' => (bool) auth()->user()?->hasAnyRole(['Manager', 'Super Admin']),
        ])->layout('layouts.optical');
    }
}
