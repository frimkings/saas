<?php

namespace App\Livewire\Platform;

use App\Models\PricingRequest;
use App\Models\SubscriptionPlan;
use App\Services\PlatformAuditService;
use App\Services\PricingCatalogue;
use App\Support\Pricing\AttendanceSuitePrices;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Platform → Pricing Page: requests from the public pricing page to follow up, which plans the
 * page shows (in order, with the busiest clinic each is meant for and a one-line pitch), and the
 * Attendance Suite prices (by staff size, packages, modules, special pricing from a staff number).
 */
class PricingRequestsComponent extends Component
{
    use WithPagination;

    public string $tab = 'requests';
    public string $status = 'open';
    public ?int $openId = null;
    public string $notes = '';
    /** family => ['show' => bool, 'order' => int, 'max_visits' => ?string, 'tagline' => string, 'featured' => bool] as edited. */
    public array $page = [];
    /** The attendance price list as edited: sizes [[up_to, price]], special_from, packages [key => [uplift, tagline]], modules [key => price], featured. */
    public array $att = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $this->loadPage();
        $this->loadAttendance();
    }

    private function loadAttendance(): void
    {
        $this->att = [
            'sizes' => collect(AttendanceSuitePrices::sizes())->map(fn ($price, $upTo) => ['up_to' => (string) $upTo, 'price' => self::plain($price)])->values()->all(),
            'special_from' => (string) AttendanceSuitePrices::specialFrom(),
            'packages' => collect(AttendanceSuitePrices::packages())->except('basic')->map(fn ($p) => ['uplift' => self::plain($p['uplift'])])->all(),
            'taglines' => collect(AttendanceSuitePrices::packages())->map(fn ($p) => $p['tagline'])->all(),
            'modules' => collect(AttendanceSuitePrices::modules())->map(fn ($price) => self::plain($price))->all(),
            'featured' => (string) (collect(AttendanceSuitePrices::packages())->search(fn ($p) => $p['featured']) ?: ''),
        ];
    }

    private static function plain(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function loadPage(): void
    {
        $saved = PricingCatalogue::settings()['plans'];
        $this->page = [];
        foreach ($this->families() as $family => $plan) {
            $row = $saved[$family] ?? null;
            $this->page[$family] = ['show' => $row !== null, 'order' => (string) ($row['order'] ?? ''), 'max_visits' => (string) ($row['max_visits'] ?? ''),
                'tagline' => (string) ($row['tagline'] ?? ''), 'featured' => (bool) ($row['featured'] ?? false)];
        }
    }

    /** The newest active version of each plan family, cheapest first. */
    private function families()
    {
        return SubscriptionPlan::where('is_active', true)->get()->groupBy(fn ($p) => $p->family_code ?: $p->code)
            ->map(fn ($versions) => $versions->sortByDesc('version')->first())->sortBy('base_price');
    }

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, ['requests', 'page', 'attendance'], true), 422);
        $this->tab = $tab;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $this->notes = (string) PricingRequest::find($id)?->notes;
    }

    public function mark(int $id, string $status, PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        abort_unless(isset(PricingRequest::STATUSES[$status]), 422);
        $request = PricingRequest::findOrFail($id);
        $old = $request->only(['status', 'notes']);
        $request->update(['status' => $status, 'notes' => $this->openId === $id ? trim($this->notes) ?: null : $request->notes,
            'handled_by' => auth()->id(), 'handled_at' => now()]);
        $audit->record('PRICING_REQUEST_UPDATED', null, $old, ['id' => $request->id] + $request->only(['status', 'notes']));
        $this->dispatch('notify', type: 'success', message: "{$request->clinic_name}: " . PricingRequest::STATUSES[$status] . '.');
    }

    public function saveNotes(int $id): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $this->validate(['notes' => 'nullable|string|max:2000']);
        PricingRequest::findOrFail($id)->update(['notes' => trim($this->notes) ?: null]);
        $this->dispatch('notify', type: 'success', message: 'Notes saved.');
    }

    public function savePage(PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $families = $this->families();
        $this->validate([
            'page.*.order' => 'nullable|integer|min:1|max:99', 'page.*.max_visits' => 'nullable|integer|min:1|max:100000',
            'page.*.tagline' => 'nullable|string|max:120',
        ], ['page.*.order.*' => 'Enter 1–99.', 'page.*.max_visits.*' => 'Enter a number of patients, or leave it empty for no limit.']);

        $plans = [];
        foreach ($this->page as $family => $row) {
            if (! ($row['show'] ?? false) || ! $families->has($family)) continue;
            $plans[$family] = ['order' => (int) ($row['order'] ?: 99), 'max_visits' => $row['max_visits'] === '' ? null : (int) $row['max_visits'],
                'tagline' => trim((string) $row['tagline']), 'featured' => (bool) ($row['featured'] ?? false)];
        }
        $old = PricingCatalogue::settings();
        PricingCatalogue::saveSettings(['plans' => $plans] + $old);
        $audit->record('PRICING_PAGE_SAVED', null, $old, ['plans' => $plans]);
        $this->loadPage();
        $this->dispatch('notify', type: 'success', message: count($plans) . ' ' . (count($plans) === 1 ? 'plan' : 'plans') . ' on the pricing page.');
    }

    public function addSize(): void
    {
        $this->att['sizes'][] = ['up_to' => '', 'price' => ''];
    }

    public function removeSize(int $index): void
    {
        if (count($this->att['sizes']) <= 1) return;
        unset($this->att['sizes'][$index]);
        $this->att['sizes'] = array_values($this->att['sizes']);
    }

    public function saveAttendance(PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $packages = array_keys(AttendanceSuitePrices::PACKAGES);
        $this->validate([
            'att.special_from' => 'required|integer|min:2|max:100000',
            'att.sizes' => 'required|array|min:1|max:40',
            'att.sizes.*.up_to' => 'required|integer|min:1|max:99999', 'att.sizes.*.price' => 'required|numeric|min:0|max:1000000',
            'att.packages.*.uplift' => 'required|numeric|min:0|max:1000000',
            'att.taglines.*' => 'nullable|string|max:120',
            'att.modules.*' => 'required|numeric|min:0|max:1000000',
            'att.featured' => 'nullable|in:' . implode(',', $packages),
        ], [
            'att.sizes.*.up_to.*' => 'Enter a number of staff.', 'att.sizes.*.price.*' => 'Enter a price.',
            'att.packages.*.uplift.*' => 'Enter an amount (0 or more).', 'att.modules.*.*' => 'Enter a price.',
            'att.special_from.*' => 'Enter a number of staff.',
        ]);

        // Every number of staff below special pricing needs a price: sizes in order, the last one just below it.
        $sizes = collect($this->att['sizes'])->map(fn ($row) => [(int) $row['up_to'], round((float) $row['price'], 2)])->sortBy(0)->values();
        $special = (int) $this->att['special_from'];
        if ($sizes->pluck(0)->duplicates()->isNotEmpty()) $this->addError('att.sizes', 'Two sizes have the same number of staff.');
        elseif ($sizes->last()[0] !== $special - 1) $this->addError('att.sizes', "The largest size must be up to " . ($special - 1) . " staff, one below special pricing, so every number of staff has a price.");
        if ($this->getErrorBag()->isNotEmpty()) return;

        $saved = [
            'sizes' => $sizes->all(), 'special_from' => $special,
            'packages' => collect($packages)->mapWithKeys(fn ($key) => [$key => [
                'uplift' => $key === 'basic' ? 0 : round((float) $this->att['packages'][$key]['uplift'], 2),
                'tagline' => trim((string) ($this->att['taglines'][$key] ?? '')) ?: AttendanceSuitePrices::PACKAGES[$key]['tagline'],
            ]])->all(),
            'modules' => collect(AttendanceSuitePrices::DEFAULT_MODULES)->map(fn ($d, $key) => round((float) $this->att['modules'][$key], 2))->all(),
            'featured' => $this->att['featured'] ?: null,
        ];
        $old = PricingCatalogue::settings();
        PricingCatalogue::saveSettings(['attendance' => $saved] + $old);
        $audit->record('PRICING_PAGE_ATTENDANCE_SAVED', null, ['attendance' => $old['attendance'] ?? null], ['attendance' => $saved]);
        $this->loadAttendance();
        $this->dispatch('notify', type: 'success', message: 'Attendance prices saved. The pricing page shows them now.');
    }

    /** Back to the agreed price list in the code. */
    public function resetAttendance(PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $old = PricingCatalogue::settings();
        PricingCatalogue::saveSettings(collect($old)->except('attendance')->all() + ['plans' => []]);
        $audit->record('PRICING_PAGE_ATTENDANCE_SAVED', null, ['attendance' => $old['attendance'] ?? null], ['attendance' => null]);
        $this->resetErrorBag();
        $this->loadAttendance();
        $this->dispatch('notify', type: 'success', message: 'Attendance prices are back to the standard price list.');
    }

    public function render()
    {
        $query = PricingRequest::with('handler')->latest();
        if ($this->status === 'open') $query->whereIn('status', ['new', 'contacted']);
        elseif ($this->status !== 'all') $query->where('status', $this->status);

        return view('livewire.platform.pricing-requests-component', [
            'requests' => $query->paginate(20),
            'counts' => PricingRequest::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'families' => $this->tab === 'page' ? $this->families() : collect(),
        ])->layout('layouts.platform');
    }
}
