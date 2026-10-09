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
 * Optical → Settings → Lens options: the lens types, forms, treatments and manufacturers
 * staff choose from when receiving and ordering lenses. Forms, treatments and manufacturers
 * can be added, renamed, hidden, reordered and, while nothing uses them, deleted. Lens types
 * drive stock matching, so they are renamed, hidden and reordered only; Standard is every
 * lens type's plain form and can be renamed but not deleted.
 */
class LensOptionsComponent extends Component
{
    /** Kinds that can be added and deleted. */
    private const EDITABLE = ['form', 'treatment', 'manufacturer'];

    /** @var array<string, string> new option name per kind */
    public array $newName = ['form' => '', 'treatment' => '', 'manufacturer' => ''];
    /** The lens type a new form belongs to ('' = every lens type). */
    public string $newFormDesign = 'Bifocal';
    public ?int $editingId = null;
    public string $editingName = '';

    private function assertManager(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        app(ClinicAccessService::class)->assertWritable('optical');
    }

    public function add(string $kind): void
    {
        $this->assertManager();
        abort_unless(in_array($kind, self::EDITABLE, true), 404);
        $field = "newName.$kind";
        $name = trim(preg_replace('/\s+/', ' ', (string) ($this->newName[$kind] ?? '')));
        $this->validateName($name, $kind, $field);
        $design = null;
        if ($kind === 'form') {
            $design = $this->newFormDesign === '' ? null : $this->newFormDesign;
            abort_unless($design === null || array_key_exists($design, LensOptions::DESIGNS), 422);
        }
        // The name becomes the code stock keeps; a clash with an old code gets a number.
        $code = $name;
        for ($n = 2; OpticalLensOption::where('kind', $kind)->where('code', $code)->exists(); $n++) $code = "{$name} {$n}";
        $option = new OpticalLensOption(['kind' => $kind, 'code' => $code, 'name' => $name, 'design' => $design, 'is_active' => true,
            'sort_order' => (int) OpticalLensOption::where('kind', $kind)->max('sort_order') + 1]);
        AuditTrail::recordSave($option, 'optical.lens_'.$kind, 'lens '.$kind.' '.$name);
        $this->newName[$kind] = '';
        LensOptions::forget();
        session()->flash('success', '“'.$name.'” added.');
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
        if ($option->kind === 'form' && $option->code === LensOptions::STANDARD_FORM && $option->is_active) {
            throw ValidationException::withMessages(['options' => 'Standard is the form of every plain lens, so it stays available.']);
        }
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

    /** An option no stock or price list uses can be removed; one in use can only be hidden. */
    public function delete(int $id): void
    {
        $this->assertManager();
        $option = OpticalLensOption::whereIn('kind', self::EDITABLE)->findOrFail($id);
        abort_if($option->kind === 'form' && $option->code === LensOptions::STANDARD_FORM, 422);
        if ($this->inUse($option)) {
            throw ValidationException::withMessages(['options' => "“{$option->name}” is on lens stock or a price list, so it can't be deleted. Hide it instead."]);
        }
        $option->delete();
        AuditTrail::record('optical.lens_'.$option->kind.'_deleted', 'Deleted lens '.$option->kind.' '.$option->name, $option, $option->only(['code', 'name']), []);
        LensOptions::forget();
        session()->flash('success', '“'.$option->name.'” deleted.');
    }

    private function inUse(OpticalLensOption $option): bool
    {
        if ($option->kind === 'design' || ($option->kind === 'form' && $option->code === LensOptions::STANDARD_FORM)) return true;
        $field = LensOptions::SPEC_FIELDS[$option->kind];
        return OpticalProduct::withTrashed()->whereNotNull('lens_specs')->where("lens_specs->{$field}", $option->code)->exists()
            || OpticalLensPrice::where("specs->{$field}", $option->code)->exists();
    }

    private function validateName(string $name, string $kind, string $field, ?int $ignoreId = null): void
    {
        $max = $kind === 'manufacturer' ? 100 : 40;
        if ($name === '' || mb_strlen($name) > $max) {
            throw ValidationException::withMessages([$field => "Enter a name of up to {$max} characters."]);
        }
        $taken = OpticalLensOption::where('kind', $kind)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()->contains(fn ($option) => mb_strtolower($option->name) === mb_strtolower($name));
        if ($taken) throw ValidationException::withMessages([$field => "There is already one called “{$name}”."]);
    }

    public function render()
    {
        $kinds = [];
        foreach (['design', 'form', 'treatment', 'manufacturer'] as $kind) {
            $options = LensOptions::all($kind);
            $kinds[$kind] = ['options' => $options, 'inUse' => $options->mapWithKeys(fn ($option) => [$option->id => $this->inUse($option)])];
        }
        return view('livewire.optical.lens-options-component', ['kinds' => $kinds]);
    }
}
