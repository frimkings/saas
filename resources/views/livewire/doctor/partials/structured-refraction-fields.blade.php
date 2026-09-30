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
<div class="alert alert-warning py-2 mb-2">
    <strong><i class="fas fa-history mr-1"></i> Previous-format refraction.</strong>
    Verify before entering structured measurements. <strong>OD:</strong> {{ $state['refractionOD'] ?? '—' }} · <strong>OS:</strong> {{ $state['refractionOS'] ?? '—' }}
</div>
@endif

@if($previousRefraction)
<details class="mb-2 border rounded bg-light px-2 py-1">
 <summary class="small" style="cursor:pointer;"><strong><i class="fas fa-history mr-1"></i>Previous refraction</strong> · OD {{ $previousRefraction->subjectiveRx('od') ?: '—' }} · OS {{ $previousRefraction->subjectiveRx('os') ?: '—' }} · {{ $previousRefraction->created_at?->format('d M Y') }}</summary>
 <div class="d-flex justify-content-between align-items-center flex-wrap pt-2 pb-1" style="gap:8px;"><small class="text-muted">Recorded by {{ $previousRefraction->user->name ?? 'Unknown' }}. Copied values must be clinically verified.</small><button type="button" wire:click="copyPreviousRefraction" class="btn btn-outline-primary btn-sm" {{ !$previousRefraction->hasStructuredSubjective() ? 'disabled' : '' }}><i class="fas fa-copy mr-1"></i>Copy Previous</button></div>
</details>
@endif

<div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
    <span class="badge badge-{{ $statusClass }} p-2"><i class="fas fa-glasses mr-1"></i>{{ $dispensingStatus }}</span>
    <div class="small">
        <span class="badge badge-{{ $this->refractionCompleteness['objective'] ? 'success' : 'light' }}">Objective {{ $this->refractionCompleteness['objective'] ? '✓' : '○' }}</span>
        <span class="badge badge-{{ $this->refractionCompleteness['subjective'] ? 'success' : 'light' }}">Subjective {{ $this->refractionCompleteness['subjective'] ? '✓' : '○' }}</span>
        <span class="badge badge-{{ $this->refractionCompleteness['dispensing'] ? 'success' : 'warning' }}">Dispensing {{ ($state['dispensing_required'] ?? false) ? ($this->refractionCompleteness['dispensing'] ? '✓' : 'Required') : 'Optional' }}</span>
    </div>
    @if($savedRefraction?->dispensingAuthorizedBy)
        <small class="text-muted">Authorized by {{ $savedRefraction->dispensingAuthorizedBy->name }} · {{ $savedRefraction->dispensing_authorized_at?->format('d M Y H:i') }}</small>
    @endif
</div>

<div class="row refraction-comparison">
<div class="col-lg-6"><div class="card mb-3 refraction-comparison-card refraction-objective">
 <div class="card-header bg-light py-2 d-flex align-items-center justify-content-between flex-wrap">
  <h6 class="refraction-comparison-title">
   <i class="fas fa-microscope mr-2" aria-hidden="true"></i>1. Objective Refraction
  </h6>
  <div class="w-100">
   <small class="text-muted d-none d-lg-inline">{{ $objectiveOd || $objectiveOs ? 'OD '.($objectiveOd ?: '—').' · OS '.($objectiveOs ?: '—') : 'Instrument or examiner findings' }}</small>
   <div class="d-flex align-items-center justify-content-between mt-2" style="gap:8px;">
   <select wire:model.live="state.objective_method" class="form-control form-control-sm" style="width:190px; min-width:0;" aria-label="Objective refraction method">
    <option value="">Method not recorded</option><option value="auto_refraction">Auto Refraction</option><option value="retinoscopy">Retinoscopy</option><option value="cycloplegic_refraction">Cycloplegic Refraction</option><option value="other">Other</option>
   </select>
   <button type="button" class="btn btn-primary btn-sm ml-auto" wire:click="copyObjectiveToSubjective"><i class="fas fa-copy mr-1"></i>Copy to Subjective & Continue</button>
   </div>
  </div>
 </div>
 <div id="objective-refraction-panel" class="refraction-comparison-panel">
  <div class="card-body py-2 px-3">
   <div class="table-responsive"><table class="table table-bordered table-sm mb-0"><thead class="thead-light text-center"><tr><th>Eye</th><th>Sphere</th><th>Cylinder</th><th>Axis</th><th>VA</th></tr></thead><tbody>
   @foreach(['od' => 'OD', 'os' => 'OS'] as $eye => $label)
    <tr><td class="font-weight-bold text-center align-middle">{{ $label }}</td>
     <td data-label="Sphere"><input type="number" min="-20" max="20" step="0.25" wire:model.live.debounce.300ms="state.objective_{{ $eye }}_sphere" class="form-control form-control-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Cylinder"><input type="number" min="-10" max="10" step="0.25" wire:model.live.debounce.300ms="state.objective_{{ $eye }}_cylinder" class="form-control form-control-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Axis"><input type="number" min="1" max="180" step="1" wire:model.live.debounce.300ms="state.objective_{{ $eye }}_axis" class="form-control form-control-sm refraction-control" placeholder="1–180" @disabled(blank($state['objective_'.$eye.'_cylinder'] ?? null) || (float) ($state['objective_'.$eye.'_cylinder'] ?? 0) === 0.0)></td>
     <td data-label="VA"><select wire:model.live="state.objective_{{ $eye }}_va" class="form-control form-control-sm"><option value="">—</option>@foreach($vaOptions as $l => $lm)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></td></tr>
   @endforeach
   </tbody></table></div>
   <details class="mt-2" @if(filled($state['objective_notes'] ?? null)) open @endif><summary class="text-primary font-weight-bold small" style="cursor:pointer;">{{ filled($state['objective_notes'] ?? null) ? 'Objective notes' : '+ Add objective notes' }}</summary><textarea wire:model="state.objective_notes" class="form-control form-control-sm mt-2" rows="2" placeholder="Retinoscopy, cycloplegic result, keratometry or reliability notes"></textarea></details>
  </div>
 </div>
</div>

<div class="card mb-3 refraction-dispensing">
 <div class="card-header bg-light py-2">
  <h6 class="mb-0 font-weight-bold"><i class="fas fa-glasses mr-2" aria-hidden="true"></i>3. Dispensing</h6>
 </div>
 <div id="dispensing-refraction-panel" class="refraction-comparison-panel">
  <div class="card-body py-3 px-3">
   <div class="custom-control custom-switch mb-3">
    <input type="checkbox" class="custom-control-input" id="dispensing-required" wire:model.live="state.dispensing_required">
    <label class="custom-control-label font-weight-bold" for="dispensing-required">Spectacle dispensing required</label>
    <small class="d-block text-muted">Enable this only when the final refraction should proceed to an optical order.</small>
   </div>

   @if($state['dispensing_required'] ?? false)
    <div class="row">
     <div class="col-md-4 mb-3">
      <label class="font-weight-bold">P.D. (mm) <span class="text-danger">*</span></label>
      <input type="number" min="35" max="85" step="0.1" wire:model="state.pd" class="form-control @error('state.pd') is-invalid @enderror" placeholder="e.g. 62.0">
     </div>
     <div class="col-md-8 mb-3">
      <label class="font-weight-bold">Lens Type &amp; Coating <span class="text-danger">*</span></label>
      <select wire:model.live="state.lensType" class="form-control @error('state.lensType') is-invalid @enderror">
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
     <div class="mb-3">
      <label class="font-weight-bold">Available Lens Product</label>
      <select class="form-control" wire:change="selectLensProduct($event.target.value)">
       <option value="">Choose a stocked lens to add to the prescription...</option>
       @foreach($lensProducts as $product)
        <option value="{{ $product->id }}">{{ $product->name }} — {{ currency() }} {{ number_format($product->selling_price, 2) }} ({{ $product->stockLabel() }})</option>
       @endforeach
      </select>
     </div>
    @endif
   @else
    <div class="alert alert-light border py-2 mb-2"><i class="fas fa-info-circle mr-1"></i>The refraction will be saved as a clinical record without authorizing spectacle dispensing.</div>
   @endif

   <div class="mt-2">
    <label class="font-weight-bold">Refraction Notes</label>
    <textarea wire:model="state.refractionnotes" class="form-control" rows="2" style="resize:vertical;" placeholder="Clinical impression, adaptation advice, review interval, or other instructions"></textarea>
   </div>
  </div>
 </div>
</div>

</div>
<div class="col-lg-6"><div class="card mb-3 refraction-comparison-card refraction-subjective">
 <div class="card-header bg-light py-2">
  <h6 class="refraction-comparison-title">
   <span><i class="fas fa-eye mr-2" aria-hidden="true"></i>2. Subjective Refraction</span>
   <small class="text-muted font-weight-normal d-none d-md-inline">{{ $this->refractionPreview['od'] || $this->refractionPreview['os'] ? 'OD '.($this->refractionPreview['od'] ?: '—').' · OS '.($this->refractionPreview['os'] ?: '—') : 'Final accepted correction' }}</small>
  </h6>
 </div>
 <div id="subjective-refraction-panel" class="refraction-comparison-panel">
  <div class="card-body py-2 px-3">
   <div class="d-flex flex-wrap mb-2" style="gap:6px;"><button type="button" class="btn btn-outline-secondary btn-sm" wire:click="applyRefractionPreset('plano')">Plano OU</button><button type="button" class="btn btn-outline-secondary btn-sm" wire:click="applyRefractionPreset('no_cylinder')">No Cylinder</button><button type="button" class="btn btn-outline-secondary btn-sm" wire:click="applyRefractionPreset('no_add')">No ADD</button><button type="button" class="btn btn-outline-secondary btn-sm" wire:click="applyRefractionPreset('equal_add')">Equal ADD OU</button></div>
   <div class="table-responsive"><table class="table table-bordered table-sm mb-0"><thead class="thead-light text-center"><tr><th>Eye</th><th>Sphere *</th><th>Cylinder</th><th>Axis</th><th>ADD</th><th>BCVA *</th><th>Near VA</th></tr></thead><tbody>
   @foreach(['od' => 'OD', 'os' => 'OS'] as $eye => $label)
    <tr><td class="font-weight-bold text-center align-middle">{{ $label }}</td>
     <td data-label="Sphere *"><input type="number" min="-20" max="20" step="0.25" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_sphere" class="form-control form-control-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Cylinder"><input type="number" min="-10" max="10" step="0.25" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_cylinder" class="form-control form-control-sm refraction-control" placeholder="0.00"></td>
     <td data-label="Axis"><input type="number" min="1" max="180" step="1" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_axis" class="form-control form-control-sm refraction-control" placeholder="1–180" @disabled(blank($state['subjective_'.$eye.'_cylinder'] ?? null) || (float) ($state['subjective_'.$eye.'_cylinder'] ?? 0) === 0.0)></td>
     <td data-label="ADD"><input type="number" min="0.50" max="4" step="0.25" wire:model.live.debounce.300ms="state.subjective_{{ $eye }}_add" class="form-control form-control-sm refraction-control" placeholder="None"></td>
     <td data-label="BCVA *"><select wire:model.live="state.subjective_{{ $eye }}_bcva" class="form-control form-control-sm"><option value="">Select</option>@foreach($vaOptions as $l => $lm)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></td>
     <td data-label="Near VA"><select wire:model="state.refraction{{ strtoupper($eye) }}_near_va" class="form-control form-control-sm"><option value="">—</option>@foreach($nearVaOptions as $nearVa)<option value="{{ $nearVa }}">{{ $nearVa }}</option>@endforeach</select></td></tr>
   @endforeach
   </tbody></table></div>
   <details class="mt-2" @if(filled($state['subjective_notes'] ?? null)) open @endif><summary class="text-primary font-weight-bold small" style="cursor:pointer;">{{ filled($state['subjective_notes'] ?? null) ? 'Subjective notes' : '+ Add subjective notes' }}</summary><textarea wire:model="state.subjective_notes" class="form-control form-control-sm mt-2" rows="2" placeholder="Patient acceptance, binocular balance or adaptation advice"></textarea></details>
   <div class="d-flex justify-content-between flex-wrap mt-2" style="gap:6px;"><div><button type="button" class="btn btn-outline-secondary btn-sm" wire:click="copyOdAddToOs">Copy OD ADD to OS</button> <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="copyOdNearVaToOs">Copy OD Near VA to OS</button></div><button type="button" class="btn btn-outline-primary btn-sm" wire:click="$set('refractionSection', 'dispensing')">Continue to Dispensing <i class="fas fa-arrow-right ml-1"></i></button></div>
  </div>
 </div>
</div>

@if($this->refractionPreview['od'] || $this->refractionPreview['os'])
<div class="alert alert-primary py-2 px-3 mb-2 d-flex flex-wrap align-items-center" style="gap:12px;"><strong><i class="fas fa-eye mr-1"></i>Final Rx</strong><span><strong>OD:</strong> {{ $this->refractionPreview['od'] ?: '—' }}@if($this->refractionPreview['od_add']) ADD {{ $this->refractionPreview['od_add'] }}@endif</span><span><strong>OS:</strong> {{ $this->refractionPreview['os'] ?: '—' }}@if($this->refractionPreview['os_add']) ADD {{ $this->refractionPreview['os_add'] }}@endif</span></div>
@endif

@if(count($this->refractionWarnings))
<div class="alert refraction-verification py-2 mb-3" role="alert"><strong><i class="fas fa-exclamation-triangle mr-1"></i>Please verify:</strong> {{ implode(' ', $this->refractionWarnings) }}</div>
@endif


</div></div>
