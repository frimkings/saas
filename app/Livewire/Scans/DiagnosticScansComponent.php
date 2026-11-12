<?php

namespace App\Livewire\Scans;

use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Front Desk → Diagnostic Scans: one page, a tab per part. Each tab is its own component.
 * Phase 1 has the catalogue and the partner clinics; the scan desk, commissions and reports
 * follow (docs/diagnostic-scans-design.md).
 */
class DiagnosticScansComponent extends Component
{
    public const TABS = ['desk' => 'Scan Desk', 'catalogue' => 'Scan Catalogue', 'partners' => 'Partner Clinics', 'commissions' => 'Commissions'];

    /** Tabs only managers open: what referrers are owed and paying it. */
    public const MANAGER_TABS = ['commissions'];

    #[Url(except: 'desk')]
    public string $tab = 'desk';

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::tabs())) $this->tab = 'desk';
    }

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::tabs()), 422);
        $this->tab = $tab;
    }

    /** The tabs this person sees. */
    public static function tabs(): array
    {
        return self::canManage() ? self::TABS : array_diff_key(self::TABS, array_flip(self::MANAGER_TABS));
    }

    /** Prices, commission rates and partners' payout details are a manager's to change. */
    public static function canManage(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['Manager', 'Super Admin']);
    }

    public function render()
    {
        return view('livewire.scans.diagnostic-scans-component', ['tabs' => self::tabs()])
            ->layout('layouts.clinic', ['menu' => 'reception']);
    }
}
