@php
    $sphereOptions = \App\Livewire\Doctor\PatientRecordsComponent::lensPowerOptions();
    $cylinderOptions = \App\Livewire\Doctor\PatientRecordsComponent::lensPowerOptions(-10, 10);
    $addOptions = \App\Livewire\Doctor\PatientRecordsComponent::addPowerOptions();
    $axisOptions = \App\Livewire\Doctor\PatientRecordsComponent::axisOptions();
    $vaOptions = \App\Livewire\Doctor\PatientRecordsComponent::vaLogMarTable();
    $nearVaOptions = \App\Livewire\Doctor\PatientRecordsComponent::nearVisualAcuityOptions();
    $legacyRefraction = filled($state['refractionOD'] ?? null) && blank($state['subjective_od_sphere'] ?? null);
    $savedRefraction = $consultation?->refraction;
    $dispensingStatus = $savedRefraction?->lensOrder
        ? 'Order created: '.$savedRefraction->lensOrder->order_id
        : (($state['dispensing_required'] ?? false) ? 'Awaiting spectacle order' : 'Clinical record only');
    $statusClass = $savedRefraction?->lensOrder ? 'success' : (($state['dispensing_required'] ?? false) ? 'primary' : 'secondary');
    $objectiveOd = \App\Models\Refractions::formatPrescription($state['objective_od_sphere'] ?? null, $state['objective_od_cylinder'] ?? null, $state['objective_od_axis'] ?? null);
    $objectiveOs = \App\Models\Refractions::formatPrescription($state['objective_os_sphere'] ?? null, $state['objective_os_cylinder'] ?? null, $state['objective_os_axis'] ?? null);
    $previousRefraction = $this->getPreviousRefractionProperty();
@endphp

@if($legacyRefraction)
<div class="rounded-lg border px-3 text-sm border-amber-200 bg-amber-50 text-amber-900 py-2 mb-2">
    <strong><i class="fas fa-history mr-1"></i> Previous-format refraction.</strong>
    Verify before entering structured measurements. <strong>OD:</strong> {{ $state['refractionOD'] ?? '—' }} · <strong>OS:</strong> {{ $state['refractionOS'] ?? '—' }}
</div>
@endif

@if($previousRefraction)
<details class="mb-2 border border-slate-200 rounded-md bg-slate-50 px-2 py-1">
 <summary class="text-sm" style="cursor:pointer;"><strong><i class="fas fa-history mr-1"></i>Previous refraction</strong> · OD {{ $previousRefraction->subjectiveRx('od') ?: '—' }} · OS {{ $previousRefraction->subjectiveRx('os') ?: '—' }} · {{ $previousRefraction->created_at?->format('d M Y') }}</summary>
 <div class="flex justify-between items-center flex-wrap pt-2 pb-1" style="gap:8px;"><small class="text-slate-500">Recorded by {{ $previousRefraction->user->name ?? 'Unknown' }}. Copied values must be clinically verified.</small><button type="button" wire:click="copyPreviousRefraction" class="btn ui-button ui-button-secondary ui-button-sm" {{ !$previousRefraction->hasStructuredSubjective() ? 'disabled' : '' }}><i class="fas fa-copy mr-1"></i>Copy Previous</button></div>
</details>
@endif

<div class="flex flex-wrap items-center justify-between mb-2">
    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $statusClass }} p-2"><i class="fas fa-glasses mr-1"></i>{{ $dispensingStatus }}</span>
    <div class="text-sm">
        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $this->refractionCompleteness['objective'] ? 'success' : 'light' }}">Objective {{ $this->refractionCompleteness['objective'] ? '✓' : '○' }}</span>
        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $this->refractionCompleteness['subjective'] ? 'success' : 'light' }}">Subjective {{ $this->refractionCompleteness['subjective'] ? '✓' : '○' }}</span>
        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $this->refractionCompleteness['dispensing'] ? 'success' : 'warning' }}">Dispensing {{ ($state['dispensing_required'] ?? false) ? ($this->refractionCompleteness['dispensing'] ? '✓' : 'Required') : 'Optional' }}</span>
    </div>
    @if($savedRefraction?->dispensingAuthorizedBy)
        <small class="text-slate-500">Authorized by {{ $savedRefraction->dispensingAuthorizedBy->name }} · {{ $savedRefraction->dispensing_authorized_at?->format('d M Y H:i') }}</small>
    @endif
</div>

<div class="flex flex-wrap -mx-2 refraction-comparison">
<div class="w-full lg:w-6/12 px-2"><div class="card overflow-hidden rounded-xl border border-slate-200 bg-white mb-4 refraction-comparison-card refraction-objective">
 <div class="card-header border-b border-slate-200 px-4 bg-slate-50 py-2 flex items-center justify-between flex-wrap">
  <h6 class="refraction-comparison-title">
   <i class="fas fa-microscope mr-2" aria-hidden="true"></i>1. Objective Refraction
  </h6>
  <div class="w-full">
   <small class="text-slate-500 hidden lg:inline">{{ $objectiveOd || $objectiveOs ? 'OD '.($objectiveOd ?: '—').' · OS '.($objectiveOs ?: '—') : 'Instrument or examiner findings' }}</small>
   <div class="flex items-center justify-between mt-2" style="gap:8px;">
   <select wire:model.live="state.objective_method" class="form-control ui-input ui-input-sm" style="width:190px; min-width:0;" aria-label="Objective refraction method">
    <option value="">Method not recorded</option><option value="auto_refraction">Auto Refraction</option><option value="retinoscopy">Retinoscopy</option><option value="cycloplegic_refraction">Cycloplegic Refraction</option><option value="other">Other</option>
   </select>
   <button type="button" class="btn ui-button ui-button-primary ui-button-sm ml-auto" wire:click="copyObjectiveToSubjective"><i class="fas fa-copy mr-1"></i>Copy to Subjective & Continue</button>
   </div>
  </div>
 </div>
 <div id="objective-refraction-panel" class="refraction-comparison-panel">
  <div class="card-body p-4 py-2 px-4">
   <div class="ui-table-wrap"><table class="table ui-table ui-table-sm mb-0"><thead class="text-center"><tr><th>Eye</th><th>Sphere</th><th>Cylinder</th><th>Axis</th><th>VA</th></tr></thead><tbody>
   @foreach(['od' => 'OD', 'os' => 'OS'] as $eye => $label)
    <tr><td class="font-semibold text-center align-middle">{{ $label }}</td>
     <td data-label="Sphere"><input type="number" min="-20" max="20" step="0.25" wire:model.live.debounce.300ms="state.objective_{{ $eye }}_sphere" class="form-control ui-input ui-input-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Cylinder"><input type="number" min="-10" max="10" step="0.25" wire:model.live.debounce.300ms="state.objective_{{ $eye }}_cylinder" class="form-control ui-input ui-input-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Axis"><input type="number" min="1" max="180" step="1" wire:model.live.debounce.300ms="state.objective_{{ $eye }}_axis" class="form-control ui-input ui-input-sm refraction-control" placeholder="1–180" @disabled(blank($state['objective_'.$eye.'_cylinder'] ?? null) || (float) ($state['objective_'.$eye.'_cylinder'] ?? 0) === 0.0)></td>
     <td data-label="VA"><select wire:model.live="state.objective_{{ $eye }}_va" class="form-control ui-input ui-input-sm"><option value="">—</option>@foreach($vaOptions as $l => $lm)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></td></tr>
   @endforeach
   </tbody></table></div>
   <details class="mt-2" @if(filled($state['objective_notes'] ?? null)) open @endif><summary class="text-teal-700 font-semibold text-sm" style="cursor:pointer;">{{ filled($state['objective_notes'] ?? null) ? 'Objective notes' : '+ Add objective notes' }}</summary><textarea wire:model="state.objective_notes" class="form-control ui-input ui-input-sm mt-2" rows="2" placeholder="Retinoscopy, cycloplegic result, keratometry or reliability notes"></textarea></details>
  </div>
 </div>
</div>

<div class="card overflow-hidden rounded-xl border border-slate-200 bg-white mb-4 refraction-dispensing">
 <div class="card-header border-b border-slate-200 px-4 bg-slate-50 py-2">
  <h6 class="mb-0 font-semibold"><i class="fas fa-glasses mr-2" aria-hidden="true"></i>3. Dispensing</h6>
 </div>
 <div id="dispensing-refraction-panel" class="refraction-comparison-panel">
  <div class="card-body p-4 py-4 px-4">
   <div class="flex items-center gap-2 mb-4">
    <input type="checkbox" class="rounded border-slate-300 text-teal-700" id="dispensing-required" wire:model.live="state.dispensing_required">
    <label class="font-semibold" for="dispensing-required">Spectacle dispensing required</label>
    <small class="block text-slate-500">Enable this only when the final refraction should proceed to an optical order.</small>
   </div>

   @if($state['dispensing_required'] ?? false)
    <div class="flex flex-wrap -mx-2">
     <div class="w-full md:w-4/12 px-2 mb-4">
      <label class="font-semibold">P.D. (mm) <span class="text-red-700">*</span></label>
      <input type="number" min="35" max="85" step="0.1" wire:model="state.pd" class="form-control ui-input @error('state.pd') is-invalid @enderror" placeholder="e.g. 62.0">
     </div>
     <div class="w-full md:w-8/12 px-2 mb-4">
      <label class="font-semibold">Lens Type &amp; Coating <span class="text-red-700">*</span></label>
      <select wire:model.live="state.lensType" class="form-control ui-input @error('state.lensType') is-invalid @enderror">
       <option value="">Select lens type and coating...</option>
       @if(filled($state['lensType'] ?? null) && !$lensOptions->flatten()->contains('display_name', $state['lensType']))
        <option value="{{ $state['lensType'] }}">{{ $state['lensType'] }} (Existing)</option>
       @endif
       @foreach($lensOptions as $family => $familyOptions)
        <optgroup label="{{ $family }}">
         @foreach($familyOptions as $lensOption)
          <option value="{{ $lensOption->display_name }}">{{ $lensOption->display_name }}</option>
         @endforeach
        </optgroup>
       @endforeach
      </select>
     </div>
    </div>

    @if(count($lensProducts) > 0)
     <div class="mb-4">
      <label class="font-semibold">Available Lens Product</label>
      <select class="form-control ui-input" wire:change="selectLensProduct($event.target.value)">
       <option value="">Choose a stocked lens to add to the prescription...</option>
       @foreach($lensProducts as $product)
        <option value="{{ $product->id }}" @selected((string) $refractionLensProductId === (string) $product->id)>{{ $product->name }} — {{ currency() }} {{ number_format($product->selling_price, 2) }} ({{ $product->stockLabel() }})</option>
       @endforeach
      </select>
     </div>
    @endif
   @else
    <div class="rounded-lg border px-3 text-sm bg-white text-slate-700 border-slate-200 py-2 mb-2"><i class="fas fa-info-circle mr-1"></i>The refraction will be saved as a clinical record without authorizing spectacle dispensing.</div>
   @endif

   <div class="mt-2">
    <label class="font-semibold">Refraction Notes</label>
    <textarea wire:model="state.refractionnotes" class="form-control ui-input" rows="2" style="resize:vertical;" placeholder="Clinical impression, adaptation advice, review interval, or other instructions"></textarea>
   </div>
  </div>
 </div>
</div>

</div>
<div class="w-full lg:w-6/12 px-2"><div class="card overflow-hidden rounded-xl border border-slate-200 bg-white mb-4 refraction-comparison-card refraction-subjective">
 <div class="card-header border-b border-slate-200 px-4 bg-slate-50 py-2">
  <h6 class="refraction-comparison-title">
   <span><i class="fas fa-eye mr-2" aria-hidden="true"></i>2. Subjective Refraction</span>
   <small class="text-slate-500 font-normal hidden md:inline">{{ $this->refractionPreview['od'] || $this->refractionPreview['os'] ? 'OD '.($this->refractionPreview['od'] ?: '—').' · OS '.($this->refractionPreview['os'] ?: '—') : 'Final accepted correction' }}</small>
  </h6>
 </div>
 <div id="subjective-refraction-panel" class="refraction-comparison-panel">
  <div class="card-body p-4 py-2 px-4">
   <div class="flex flex-wrap mb-2" style="gap:6px;"><button type="button" class="btn ui-button ui-button-secondary ui-button-sm" wire:click="applyRefractionPreset('plano')">Plano OU</button><button type="button" class="btn ui-button ui-button-secondary ui-button-sm" wire:click="applyRefractionPreset('no_cylinder')">No Cylinder</button><button type="button" class="btn ui-button ui-button-secondary ui-button-sm" wire:click="applyRefractionPreset('no_add')">No ADD</button><button type="button" class="btn ui-button ui-button-secondary ui-button-sm" wire:click="applyRefractionPreset('equal_add')">Equal ADD OU</button></div>
   <div class="ui-table-wrap"><table class="table ui-table ui-table-sm mb-0"><thead class="text-center"><tr><th>Eye</th><th>Sphere *</th><th>Cylinder</th><th>Axis</th><th>ADD</th><th>BCVA *</th><th>Near VA</th></tr></thead><tbody>
   @foreach(['od' => 'OD', 'os' => 'OS'] as $eye => $label)
    <tr><td class="font-semibold text-center align-middle">{{ $label }}</td>
     <td data-label="Sphere *"><input type="number" min="-20" max="20" step="0.25" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_sphere" class="form-control ui-input ui-input-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Cylinder"><input type="number" min="-10" max="10" step="0.25" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_cylinder" class="form-control ui-input ui-input-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Axis"><input type="number" min="1" max="180" step="1" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_axis" class="form-control ui-input ui-input-sm refraction-control" placeholder="1–180" @disabled(blank($state['subjective_'.$eye.'_cylinder'] ?? null) || (float) ($state['subjective_'.$eye.'_cylinder'] ?? 0) === 0.0)></td>
     <td data-label="ADD"><input type="number" min="0.50" max="4" step="0.25" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_add" class="form-control ui-input ui-input-sm refraction-control" placeholder="None"></td>
     <td data-label="BCVA *"><select wire:model.live="state.subjective_{{ $eye }}_bcva" class="form-control ui-input ui-input-sm"><option value="">Select</option>@foreach($vaOptions as $l => $lm)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></td>
     <td data-label="Near VA"><select wire:model="state.refraction{{ strtoupper($eye) }}_near_va" class="form-control ui-input ui-input-sm"><option value="">—</option>@foreach($nearVaOptions as $nearVa)<option value="{{ $nearVa }}">{{ $nearVa }}</option>@endforeach</select></td></tr>
   @endforeach
   </tbody></table></div>
   <details class="mt-2" @if(filled($state['subjective_notes'] ?? null)) open @endif><summary class="text-teal-700 font-semibold text-sm" style="cursor:pointer;">{{ filled($state['subjective_notes'] ?? null) ? 'Subjective notes' : '+ Add subjective notes' }}</summary><textarea wire:model="state.subjective_notes" class="form-control ui-input ui-input-sm mt-2" rows="2" placeholder="Patient acceptance, binocular balance or adaptation advice"></textarea></details>
   <div class="flex justify-between flex-wrap mt-2" style="gap:6px;"><div><button type="button" class="btn ui-button ui-button-secondary ui-button-sm" wire:click="copyOdAddToOs">Copy OD ADD to OS</button> <button type="button" class="btn ui-button ui-button-secondary ui-button-sm" wire:click="copyOdNearVaToOs">Copy OD Near VA to OS</button></div><button type="button" class="btn ui-button ui-button-secondary ui-button-sm" onclick="window.goToDispensing()">Continue to Dispensing <i class="fas fa-arrow-right ml-1"></i></button></div>
  </div>
 </div>
</div>

@if($this->refractionPreview['od'] || $this->refractionPreview['os'])
<div class="rounded-lg border text-sm border-teal-200 bg-teal-50 text-teal-900 py-2 px-4 mb-2 flex flex-wrap items-center" style="gap:12px;"><strong><i class="fas fa-eye mr-1"></i>Final Rx</strong><span><strong>OD:</strong> {{ $this->refractionPreview['od'] ?: '—' }}@if($this->refractionPreview['od_add']) ADD {{ $this->refractionPreview['od_add'] }}@endif</span><span><strong>OS:</strong> {{ $this->refractionPreview['os'] ?: '—' }}@if($this->refractionPreview['os_add']) ADD {{ $this->refractionPreview['os_add'] }}@endif</span></div>
@endif

@if(count($this->refractionWarnings))
<div class="rounded-lg border px-3 text-sm refraction-verification py-2 mb-4" role="alert"><strong><i class="fas fa-exclamation-triangle mr-1"></i>Please verify:</strong> {{ implode(' ', $this->refractionWarnings) }}</div>
@endif


</div></div>
