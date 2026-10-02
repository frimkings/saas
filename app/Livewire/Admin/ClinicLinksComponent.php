<?php

namespace App\Livewire\Admin;

use App\Models\Branch;
use App\Models\Setting;
use App\Support\Messaging\ClinicLinks;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;

/**
 * Settings → Clinic Links (Optical Settings for optical-only clinics): the clinic's links,
 * which every SMS template can insert by name ([MAP_LINK], [WHATSAPP_LINK], [REVIEW_LINK] ...).
 * A branch can have its own location and WhatsApp links; empty means "use the clinic's".
 */
class ClinicLinksComponent extends Component
{
    public bool $optical = false;

    /** settings column => value */
    public array $links = [];

    /** branch id => ['name', 'map_link', 'whatsapp_link'] */
    public array $branchLinks = [];

    public function mount(bool $optical = false): void
    {
        $this->optical = $optical;
        $this->authorizeAccess();
        $this->load();
    }

    public function save(): void
    {
        $this->authorizeAccess();

        // A WhatsApp number becomes a wa.me link before it is checked.
        $this->links['whatsapp_link'] = ClinicLinks::whatsapp($this->links['whatsapp_link'] ?? '') ?? '';
        foreach ($this->branchLinks as $id => $row) {
            $this->branchLinks[$id]['whatsapp_link'] = ClinicLinks::whatsapp($row['whatsapp_link'] ?? '') ?? '';
        }

        $rules = $names = [];
        foreach (ClinicLinks::LINKS as [$column, $label]) {
            $rules["links.{$column}"] = 'nullable|url:http,https|max:500';
            $names["links.{$column}"] = $label;
        }
        foreach (array_keys($this->branchLinks) as $id) {
            $rules["branchLinks.{$id}.map_link"] = 'nullable|url:http,https|max:500';
            $rules["branchLinks.{$id}.whatsapp_link"] = 'nullable|url:http,https|max:500';
            $names["branchLinks.{$id}.map_link"] = 'branch location link';
            $names["branchLinks.{$id}.whatsapp_link"] = 'branch WhatsApp link';
        }
        $this->validate($rules, ['url' => 'Enter a full web address starting with https://'], $names);

        $values = [];
        foreach (ClinicLinks::LINKS as [$column]) {
            $values[$column] = trim((string) ($this->links[$column] ?? '')) ?: null;
        }
        Setting::getSettings()->update($values);

        foreach ($this->branchLinks as $id => $row) {
            Branch::where('clinic_id', $this->clinicId())->whereKey($id)->update([
                'map_link'      => trim((string) ($row['map_link'] ?? '')) ?: null,
                'whatsapp_link' => trim((string) ($row['whatsapp_link'] ?? '')) ?: null,
            ]);
        }

        $this->load();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Clinic links saved.']);
    }

    private function load(): void
    {
        $settings = Setting::getSettings();
        $this->links = [];
        foreach (ClinicLinks::LINKS as [$column]) {
            $this->links[$column] = (string) ($settings->{$column} ?? '');
        }

        // Branch links only matter when there is more than one branch.
        $branches = $this->clinicId()
            ? Branch::where('clinic_id', $this->clinicId())->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get()
            : collect();
        $this->branchLinks = $branches->count() > 1
            ? $branches->mapWithKeys(fn (Branch $branch) => [$branch->id => [
                'name' => $branch->name,
                'map_link' => (string) ($branch->map_link ?? ''),
                'whatsapp_link' => (string) ($branch->whatsapp_link ?? ''),
            ]])->all()
            : [];
    }

    private function clinicId(): ?int
    {
        return app(TenantContext::class)->clinicId() ?? Setting::getSettings()->clinic_id;
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless($user?->hasRole('Super Admin') || ($this->optical && $user?->hasRole('Manager')), 403);
    }

    public function render()
    {
        return view('livewire.admin.clinic-links-component');
    }
}
