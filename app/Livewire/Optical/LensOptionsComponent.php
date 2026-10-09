<?php

namespace App\Livewire\Optical;

use App\Models\AuditTrail;
use App\Models\OpticalLensOption;
use App\Models\OpticalLensPrice;
use App\Models\OpticalProduct;
use App\Services\ClinicAccessService;
use App\Support\Optical\LensOptions;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Optical → Settings → Lens options: the lens designs and treatments staff choose from when
 * receiving and ordering lenses. Treatments can be added, renamed, hidden, reordered and,
 * while unused, deleted. Designs drive stock matching, so they are renamed, hidden and
 * reordered only.
 */
class LensOptionsComponent extends Component
{
    public string $newTreatment = '';
    public ?int $editingId = null;
    public string $editingName = '';

    private function assertManager(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        app(ClinicAccessService::class)->assertWritable('optical');
    }

    public function addTreatment(): void
    {
        $this->assertManager();
        $name = trim(preg_replace('/\s+/', ' ', $this->newTreatment));
        $this->validateName($name, 'treatment', 'newTreatment');
        // The name becomes the code stock keeps; a clash with an old code gets a number.
        $code = $name;
        for ($n = 2; OpticalLensOption::where('kind', 'treatment')->where('code', $code)->exists(); $n++) $code = "{$name} {$n}";
        $option = new OpticalLensOption(['kind' => 'treatment', 'code' => $code, 'name' => $name, 'is_active' => true,
            'sort_order' => (int) OpticalLensOption::where('kind', 'treatment')->max('sort_order') + 1]);
        AuditTrail::recordSave($option, 'optical.lens_treatment', 'lens treatment '.$name);
        $this->reset('newTreatment');
        LensOptions::forget();
        session()->flash('success', "Treatment “{$name}” added.");
    }

    public function edit(int $id): void
    {
        $this->assertManager();
        $option = OpticalLensOption::findOrFail($id);
        $this->editingId = $option->id;
        $this->editingName = $option->name;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingName']);
        $this->resetValidation();
    }

    /** Renaming changes only the name shown; stock keeps the option's code. */
    public function saveName(): void
    {
        $this->assertManager();
        $option = OpticalLensOption::findOrFail((int) $this->editingId);
        $name = trim(preg_replace('/\s+/', ' ', $this->editingName));
        $this->validateName($name, $option->kind, 'editingName', $option->id);
        $option->name = $name;
        AuditTrail::recordSave($option, 'optical.lens_'.$option->kind, 'lens '.$option->kind.' '.$name);
        $this->cancelEdit();
        LensOptions::forget();
        session()->flash('success', 'Name saved. Stock and past orders are unchanged.');
    }

    /** Hidden options stay on existing stock and orders but can't be chosen for new ones. */
    public function toggle(int $id): void
    {
        $this->assertManager();
        $option = OpticalLensOption::findOrFail($id);
        if ($option->is_active && OpticalLensOption::where('kind', $option->kind)->where('is_active', true)->count() === 1) {
            throw ValidationException::withMessages(['options' => 'Keep at least one '.$option->kind.' available.']);
        }
        $option->is_active = ! $option->is_active;
        AuditTrail::recordSave($option, 'optical.lens_'.$option->kind, 'lens '.$option->kind.' '.$option->name.($option->is_active ? ' (shown)' : ' (hidden)'));
        LensOptions::forget();
    }

    public function move(int $id, int $step): void
    {
        $this->assertManager();
        $option = OpticalLensOption::findOrFail($id);
        $list = OpticalLensOption::where('kind', $option->kind)->orderBy('sort_order')->orderBy('id')->get()->values();
        $from = $list->search(fn ($row) => $row->id === $option->id);
        $to = max(0, min($list->count() - 1, $from + ($step < 0 ? -1 : 1)));
        if ($from === $to) return;
        $moved = $list->splice($from, 1)->first();
        $list->splice($to, 0, [$moved]);
        $list->each(fn ($row, $i) => $row->sort_order !== $i + 1 ? $row->update(['sort_order' => $i + 1]) : null);
        LensOptions::forget();
    }

    /** A treatment no stock or price list uses can be removed; one in use can only be hidden. */
    public function delete(int $id): void
    {
        $this->assertManager();
        $option = OpticalLensOption::where('kind', 'treatment')->findOrFail($id);
        if ($this->inUse($option)) {
            throw ValidationException::withMessages(['options' => "“{$option->name}” is on lens stock or a price list, so it can't be deleted. Hide it instead."]);
        }
        $option->delete();
        AuditTrail::record('optical.lens_treatment_deleted', 'Deleted lens treatment '.$option->name, $option, $option->only(['code', 'name']), []);
        LensOptions::forget();
        session()->flash('success', "Treatment “{$option->name}” deleted.");
    }

    private function inUse(OpticalLensOption $option): bool
    {
        return OpticalProduct::withTrashed()->whereNotNull('lens_specs')->where('lens_specs->coating', $option->code)->exists()
            || OpticalLensPrice::where('specs->coating', $option->code)->exists();
    }

    private function validateName(string $name, string $kind, string $field, ?int $ignoreId = null): void
    {
        if ($name === '' || mb_strlen($name) > 40) {
            throw ValidationException::withMessages([$field => 'Enter a name of up to 40 characters.']);
        }
        $taken = OpticalLensOption::where('kind', $kind)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()->contains(fn ($option) => mb_strtolower($option->name) === mb_strtolower($name));
        if ($taken) throw ValidationException::withMessages([$field => "There is already a {$kind} called “{$name}”."]);
    }

    public function render()
    {
        $treatments = LensOptions::all('treatment');
        return view('livewire.optical.lens-options-component', [
            'designs' => LensOptions::all('design'),
            'treatments' => $treatments,
            'inUse' => $treatments->mapWithKeys(fn ($option) => [$option->id => $this->inUse($option)]),
        ]);
    }
}
