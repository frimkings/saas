<div class="clinic-ui ui-page space-y-6">
    <!-- Top Header -->
    <div class="ui-heading flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">{{ $remakeOfId ? 'Remake Glasses' : ($editingQuotationId ? 'Edit Optical Quotation' : 'Create Order / Quote') }}</h1>
            <p class="ui-muted text-xs">Create prescription orders or service jobs for customers and partner clinics.</p>
        </div>
        <div>
            <span class="px-3 py-1 rounded-full text-xs font-semibold bg-teal-50 text-teal-800 border border-teal-200 shadow-sm">
                Step {{ $currentStep }} of 7: 
                @if($currentStep === 1) Job Source
                @elseif($currentStep === 2) Prescription & Lenses
                @elseif($currentStep === 3) Frame Details
                @elseif($currentStep === 4) Fitting & Instructions
                @elseif($currentStep === 5) Services & Pricing
                @elseif($currentStep === 6) Deposit & Payment
                @else Review & Confirm
                @endif
            </span>
        </div>
    </div>
    @if($errors->any())
        <div class="ui-panel p-3 text-red-700" role="alert">{{ $errors->first() }}</div>
    @endif

    <!-- 7-Step Navigation Indicator Bar -->
    <div class="grid grid-cols-4 md:grid-cols-7 gap-2 text-center text-xs font-medium">
        @php
            $steps = [
                1 => '1. Source & Job',
                2 => '2. Rx & Lenses',
                3 => '3. Frame',
                4 => '4. Fitting',
                5 => '5. Services & Price',
                6 => '6. Deposit',
                7 => '7. Review',
            ];
        @endphp
        @foreach($steps as $stepNum => $stepLabel)
            <button wire:click="setStep({{ $stepNum }})" type="button" 
                wire:loading.attr="disabled"
                @disabled($stepNum > $currentStep && $stepNum !== $nextStep)
                class="py-2.5 px-2 rounded-lg border transition-all flex items-center justify-center font-semibold text-[11px]
                {{ $currentStep === $stepNum 
                    ? 'bg-teal-600 text-white border-teal-700 shadow-sm font-bold' 
                    : ($currentStep > $stepNum 
                        ? 'bg-emerald-700 text-white border-emerald-800 font-semibold' 
                        : 'bg-slate-100 text-slate-500 border-slate-200 hover:bg-slate-200') 
                }}">
                {{ $stepLabel }}
            </button>
        @endforeach
    </div>

    <!-- Main Card Body -->
    <div class="ui-panel bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-6">
        
        <!-- STEP 1: CUSTOMER -->
        @if($currentStep === 1)
            @if($remakeOfId)
                @php $original = \App\Models\LensOrder::find($remakeOfId); @endphp
                <section class="rounded-lg border border-amber-300 bg-amber-50 p-4 space-y-3" aria-label="Remake details">
                    <div>
                        <h2 class="text-sm font-bold text-amber-900">Remake of order {{ $original?->order_id }}</h2>
                        <p class="text-xs text-amber-900">
                            Same customer and prescription, re-glazed into the customer's existing frame. Change the prescription on step 2 if it has changed.
                            @if($original?->warranty_expires_at)
                                Warranty {{ $original->isUnderWarranty() ? 'valid until' : 'expired on' }} {{ $original->warranty_expires_at->format('d M Y') }}.
                            @else
                                No warranty recorded on the original order.
                            @endif
                        </p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <label class="block font-bold text-slate-700">Reason for remake *
                            <select wire:model.live="remake_reason" class="ui-input mt-1 w-full bg-white font-normal">
                                <option value="">Choose a reason</option>
                                @foreach(\App\Models\LensOrder::REMAKE_REASONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            @error('remake_reason')<span class="mt-1 block font-normal text-red-600">{{ $message }}</span>@enderror
                        </label>
                        <fieldset>
                            <legend class="font-bold text-slate-700">Charge *</legend>
                            <div class="mt-1 flex gap-2">
                                <label class="flex items-center gap-2 rounded-lg border bg-white px-3 py-2 {{ $remake_charge === 'free' ? 'border-teal-600' : 'border-slate-200' }}"><input type="radio" wire:model.live="remake_charge" value="free"> Free (warranty / our error)</label>
                                <label class="flex items-center gap-2 rounded-lg border bg-white px-3 py-2 {{ $remake_charge === 'charged' ? 'border-teal-600' : 'border-slate-200' }}"><input type="radio" wire:model.live="remake_charge" value="charged"> Charged</label>
                            </div>
                            @error('remake_charge')<span class="mt-1 block text-red-600">{{ $message }}</span>@enderror
                        </fieldset>
                    </div>
                </section>
            @endif
            <div class="border-b border-slate-200 pb-3">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">1. Job Source & Requester</h2>
                <p class="text-xs text-slate-500">Use the existing patient registry for in-clinic work, select a partner clinic, or enter walk-in work details.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                <div class="md:col-span-6">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Order source *</label>
                    <select id="order-source" onchange="const source=this.value; const isPatient=source==='in_clinic'; const isPartner=source==='partner'; const name=document.getElementById('order-customer-name'); const phone=document.getElementById('order-customer-phone'); const bill=document.getElementById('order-bill-to'); document.getElementById('partner-requester-panel').style.display=isPartner?'':'none'; document.getElementById('patient-requester-panel').style.display=isPatient?'':'none'; document.getElementById('partner-billing-panel').style.display=isPartner?'':'none'; name.readOnly=isPatient; phone.readOnly=isPatient; name.classList.toggle('bg-slate-50',isPatient); phone.classList.toggle('bg-slate-50',isPatient); if(!isPatient){ name.value=''; phone.value=''; } if(isPartner){ bill.value='partner'; } document.getElementById('requester-name-label').textContent=isPatient?'Patient name':(isPartner?'Wearer name (optional)':'Walk-in customer name *'); document.getElementById('order-reference').placeholder=isPartner?'Partner clinic job reference':'Job reference or customer request';" class="ui-input w-full text-xs bg-white">
                        <option value="in_clinic" @selected($order_source === 'in_clinic')>In-clinic patient</option>
                        <option value="partner" @selected($order_source === 'partner')>Partner clinic</option>
                        <option value="walk_in" @selected($order_source === 'walk_in')>Walk-in work order</option>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Immediate walk-in retail purchases are handled in <a wire:navigate href="{{ route('optical.pos') }}" class="text-teal-700 underline">Optical POS</a>.</p>
                </div>
                <div class="md:col-span-6">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Work type *</label>
                    <select id="order-work-type" class="ui-input w-full text-xs bg-white">
                        <option value="prescription">Prescription glasses / lenses</option>
                        <option value="service">Optical service job</option>
                    </select>
                </div>
                <div id="partner-billing-panel" class="md:col-span-3" style="{{ $order_source === 'partner' ? '' : 'display:none' }}">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Bill to *</label>
                    <select id="order-bill-to" onchange="const customer=this.value==='customer'; document.getElementById('requester-name-label').textContent=customer?'Customer / wearer name *':'Wearer name (optional)';" class="ui-input w-full text-xs bg-white">
                        <option value="customer">Customer</option>
                        <option value="partner">Partner / referring clinic</option>
                    </select>
                </div>

                <div id="partner-requester-panel" class="md:col-span-9" style="{{ $order_source === 'partner' ? '' : 'display:none' }}">
                    <input id="selected-partner-id" type="hidden" value="{{ $partner_id }}">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Partner clinic *</label>
                    <div id="selected-partner-summary" style="{{ $partner_id ? '' : 'display:none' }}" class="flex min-h-11 items-center justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs">
                        <span><strong id="selected-partner-name">{{ $partner_clinic_name }}</strong><span class="ml-2 text-teal-700">Selected facility</span></span>
                        <button type="button" onclick="document.getElementById('selected-partner-id').value=''; document.getElementById('selected-partner-summary').style.display='none'; document.getElementById('partner-picker').style.display='block'" class="font-semibold text-teal-800 underline">Change</button>
                    </div>
                    <div id="partner-picker" style="{{ $partner_id ? 'display:none' : '' }}">
                        <span class="sr-only">Find partner clinic</span>
                        <input type="search" placeholder="Search partner clinic name, phone, or contact person" autocomplete="off"
                            oninput="const term=this.value.toLowerCase().trim(); document.querySelectorAll('#partner-clinic-results [data-partner-search]').forEach(row => row.style.display=row.dataset.partnerSearch.includes(term)?'':'none')"
                            class="ui-input w-full text-xs">
                        <div id="partner-clinic-results" class="mt-1 max-h-40 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-sm" role="listbox" aria-label="Available partner clinics">
                            @forelse($partners as $partner)
                                <button type="button" onclick="const name=document.getElementById('order-customer-name'); const phone=document.getElementById('order-customer-phone'); name.readOnly=false; phone.readOnly=false; name.classList.remove('bg-slate-50'); phone.classList.remove('bg-slate-50'); document.getElementById('selected-partner-id').value='{{ $partner->id }}'; document.getElementById('selected-partner-name').textContent=@js($partner->name); document.getElementById('partner-picker').style.display='none'; document.getElementById('selected-partner-summary').style.display='flex'" data-partner-search="{{ strtolower($partner->name.' '.$partner->phone.' '.$partner->contact_person) }}" class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 text-left text-xs hover:bg-teal-50" role="option">
                                    <span><span class="font-semibold">{{ $partner->name }}</span> · {{ $partner->phone ?: 'No phone' }} @if($partner->contact_person)<span class="text-slate-500">· {{ $partner->contact_person }}</span>@endif</span>
                                    <span class="shrink-0 font-semibold text-teal-700">Select</span>
                                </button>
                            @empty
                                <p class="p-3 text-xs text-slate-500">No active partner clinics are registered for this optical workspace.</p>
                            @endforelse
                        </div>
                        <a wire:navigate href="{{ route('optical.partners') }}" class="text-xs text-teal-700 underline">Manage partner clinics</a>
                    </div>
                    @error('partner_id') <p class="mt-1 text-xs text-red-600">Select a partner clinic from the search results.</p> @enderror
                </div>

                <div id="patient-requester-panel" class="md:col-span-9" style="{{ $order_source === 'in_clinic' ? '' : 'display:none' }}">
                    <label for="optical-customer-search" class="block text-xs font-bold text-slate-700 mb-1">Find existing patient *</label>
                    <input id="optical-customer-search" type="search" wire:model.live.debounce.250ms="customerSearch" placeholder="Search patient name, phone, or PX number" autocomplete="off" class="ui-input w-full text-xs">
                    @if($customerSearch !== '')
                        <div class="mt-1 max-h-52 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-sm" role="listbox" aria-label="Matching patients">
                            @forelse($customers as $customer)
                                <button type="button" wire:key="patient-option-{{ $customer->id }}" wire:click="choosePatient({{ $customer->id }})" class="block w-full border-b border-slate-100 px-3 py-2 text-left text-xs hover:bg-teal-50" role="option">
                                    <span class="font-semibold">{{ $customer->name }}</span> · {{ $customer->contact }} <span class="text-slate-500">{{ $customer->pxnumber }}</span>
                                </button>
                            @empty
                                <p class="px-3 py-2 text-xs text-slate-500">No matching patient in this clinic's registry.</p>
                            @endforelse
                        </div>
                    @endif
                    @if($patient_id)<p class="mt-2 text-xs text-teal-800"><strong>Selected:</strong> {{ $customer_name }} · {{ $customer_phone }}</p>@endif
                    @error('patient_id') <p class="mt-1 text-xs text-red-600">Select an existing patient from the search results.</p> @enderror
                </div>
                <div class="md:col-span-6">
                    <label id="requester-name-label" class="block text-xs font-bold text-slate-700 mb-1">{{ $order_source === 'partner' ? ($bill_to === 'customer' ? 'Customer / wearer name *' : 'Wearer name (optional)') : ($order_source === 'in_clinic' ? 'Patient name' : 'Walk-in customer name *') }}</label>
                    @if($order_source === 'in_clinic')<input id="order-customer-name" autocomplete="off" type="text" value="{{ $customer_name }}" readonly class="ui-input w-full text-xs bg-slate-50">@else<input id="order-customer-name" autocomplete="off" type="text" value="{{ $customer_name }}" maxlength="255" class="ui-input w-full text-xs">@endif
                    @error('customer_name')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Phone Number</label>
                    @if($order_source === 'in_clinic')<input id="order-customer-phone" autocomplete="off" type="text" value="{{ $customer_phone }}" readonly class="ui-input w-full text-xs bg-slate-50">@else<input id="order-customer-phone" autocomplete="off" type="tel" value="{{ $customer_phone }}" maxlength="50" class="ui-input w-full text-xs">@endif
                    @error('customer_phone')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-3">
                    <label for="order-reference" class="block text-xs font-bold text-slate-700 mb-1">Reference {{ $order_source === 'partner' && ! trim((string) $customer_name) ? '*' : '' }}</label>
                    <input id="order-reference" autocomplete="off" type="text" value="{{ $reference }}" maxlength="255" placeholder="{{ $order_source === 'partner' ? 'Partner clinic job reference' : 'Job reference or customer request' }}" class="ui-input w-full text-xs">
                    @error('reference') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            @if($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700" role="alert">
                    <strong>Complete the required information:</strong> {{ $errors->first() }}
                </div>
            @endif
            @if($work_type === 'service')
                <div class="max-w-4xl space-y-3">
                    <div><h3 class="text-sm font-bold text-slate-900">Services on this job</h3><p class="text-xs text-slate-500">Select one or more services. Prices come from the optical service catalogue.</p></div>
                    @if($serviceCatalogue->isEmpty())<p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">No active services. A manager can set prices and activate services in <a wire:navigate href="{{ route('optical.catalogue', ['activeTab' => 'services']) }}" class="underline font-semibold">Optical Services & Prices</a>.</p>@endif
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2" role="group" aria-label="Select optical services">
                        @foreach($serviceCatalogue as $service)
                            @php $selected = collect($service_lines)->contains(fn ($line) => (int) ($line['service_id'] ?? 0) === $service->id); @endphp
                            <label wire:key="service-choice-{{ $service->id }}" class="flex items-center justify-between gap-3 rounded-lg border p-3 cursor-pointer {{ $selected ? 'border-teal-500 bg-teal-50' : 'border-slate-200 bg-white' }}">
                                <span class="flex items-center gap-2"><input type="checkbox" wire:click="toggleService({{ $service->id }})" @checked($selected) class="accent-teal-700"><span class="text-xs font-semibold">{{ $service->name }}</span></span>
                                <span class="text-xs font-mono text-teal-800 whitespace-nowrap">{{ currency() }} {{ number_format((float) $service->price, 2) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if($service_lines)
                        <div class="rounded-lg border border-slate-200 overflow-hidden">
                            @foreach($service_lines as $index => $line)
                                @php $selectedService = $serviceCatalogue->firstWhere('id', (int) ($line['service_id'] ?? 0)); @endphp
                                @if($selectedService)
                                    <div wire:key="selected-service-{{ $selectedService->id }}" class="flex flex-wrap items-center justify-between gap-3 p-3 border-b border-slate-100 text-xs">
                                        <div><strong>{{ $selectedService->name }}</strong><span class="text-slate-500 ml-2">{{ $selectedService->requires_rx ? 'Rx required' : 'No Rx' }}{{ $selectedService->requires_frame ? ' · Frame details required' : '' }}</span></div>
                                        <div class="flex items-center gap-3"><label>Qty <input autocomplete="off" type="number" min="1" max="1000" step="1" wire:model="service_lines.{{ $index }}.quantity" class="ui-input w-20 text-xs ml-1"></label><span class="font-mono">{{ currency() }} {{ number_format((float) $selectedService->price, 2) }} each</span></div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    @error('service_lines') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif

        <!-- STEP 2: PRESCRIPTION (RX) -->
        @elseif($currentStep === 2)
            <div class="border-b border-slate-200 pb-3">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">2. Optical Prescription &amp; Lens Specification</h2>
                <p class="text-xs text-slate-500">Choose a saved prescription or enter the powers. Heights and PDs are taken at fitting.</p>
            </div>
            <div class="space-y-4" x-data="{
                odCyl: @js((string) $rx_od_cyl), odAxis: @js((string) $rx_od_axis),
                osCyl: @js((string) $rx_os_cyl), osAxis: @js((string) $rx_os_axis),
                axisMessage(cylinder, axis) {
                    const cyl = String(cylinder ?? '').trim();
                    const value = String(axis ?? '').trim();
                    if (cyl !== '' && value === '') return 'Enter an axis when cylinder is entered.';
                    if (value !== '' && (!/^\d+$/.test(value) || Number(value) > 180)) return 'Axis must be a whole number from 0 to 180.';
                    return '';
                }
            }">
                @if($optical_prescription_id || $refraction_id)
                    <div class="p-2 rounded bg-teal-50 text-teal-800 text-xs flex items-center justify-between gap-3">
                        <span>This order will use the selected prescription's saved values.</span>
                        <button type="button" wire:click="clearPrescription" class="underline font-semibold whitespace-nowrap">Enter manually</button>
                    </div>
                @endif
                @if($clinicRefractions->isNotEmpty())
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Authorized Clinic Refraction</label>
                        <select wire:change="chooseRefraction($event.target.value)" class="ui-input w-full text-xs bg-white">
                            <option value="">Select a clinic refraction</option>
                            @foreach($clinicRefractions as $refraction)
                                <option value="{{ $refraction->id }}" @selected((int) $refraction_id === (int) $refraction->id)>{{ $refraction->created_at?->format('d M Y') }} · OD {{ $refraction->subjective_od_sphere }} / OS {{ $refraction->subjective_os_sphere }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if($prescriptions->isNotEmpty())
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Saved Prescription</label>
                        <select wire:change="choosePrescription($event.target.value)" class="ui-input w-full text-xs bg-white">
                            <option value="">Enter measurements below</option>
                            @foreach($prescriptions as $prescription)
                                <option value="{{ $prescription->id }}" @selected((int) $optical_prescription_id === (int) $prescription->id)>{{ ucfirst($prescription->source) }} · {{ $prescription->prescribed_at?->format('d M Y') }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-center border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-900 text-white font-semibold uppercase text-[10px] tracking-wider">
                                <th class="p-2 border border-slate-800">Eye</th>
                                <th class="p-2 border border-slate-800">SPH</th>
                                <th class="p-2 border border-slate-800">CYL</th>
                                <th class="p-2 border border-slate-800">AXIS</th>
                                <th class="p-2 border border-slate-800">ADD</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="bg-slate-50">
                                <td class="p-2 border border-slate-200 font-bold text-teal-800">Right (OD)</td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-od-sph" type="text" value="{{ $rx_od_sph }}" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded @error('rx_od_sph') border-red-500 @enderror"></td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-od-cyl" type="text" value="{{ $rx_od_cyl }}" x-model="odCyl" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded @error('rx_od_cyl') border-red-500 @enderror"></td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-od-axis" type="number" min="0" max="180" step="1" value="{{ $rx_od_axis }}" x-model="odAxis" x-bind:required="String(odCyl).trim() !== ''" x-bind:aria-invalid="axisMessage(odCyl, odAxis) ? 'true' : 'false'" x-bind:class="axisMessage(odCyl, odAxis) ? 'border-red-500' : ''" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded"></td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-od-add" type="text" value="{{ $rx_od_add }}" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded @error('rx_od_add') border-red-500 @enderror"></td>
                            </tr>
                            <tr class="bg-slate-50">
                                <td class="p-2 border border-slate-200 font-bold text-teal-800">Left (OS)</td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-os-sph" type="text" value="{{ $rx_os_sph }}" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded @error('rx_os_sph') border-red-500 @enderror"></td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-os-cyl" type="text" value="{{ $rx_os_cyl }}" x-model="osCyl" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded @error('rx_os_cyl') border-red-500 @enderror"></td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-os-axis" type="number" min="0" max="180" step="1" value="{{ $rx_os_axis }}" x-model="osAxis" x-bind:required="String(osCyl).trim() !== ''" x-bind:aria-invalid="axisMessage(osCyl, osAxis) ? 'true' : 'false'" x-bind:class="axisMessage(osCyl, osAxis) ? 'border-red-500' : ''" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded"></td>
                                <td class="p-1 border border-slate-200"><input autocomplete="off" id="rx-os-add" type="text" value="{{ $rx_os_add }}" @readonly($optical_prescription_id || $refraction_id) class="w-full text-center font-mono py-1 text-xs border rounded @error('rx_os_add') border-red-500 @enderror"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p x-show="axisMessage(odCyl, odAxis)" x-text="'Right eye: ' + axisMessage(odCyl, odAxis)" class="text-xs text-red-600" role="alert" style="display:none"></p>
                <p x-show="axisMessage(osCyl, osAxis)" x-text="'Left eye: ' + axisMessage(osCyl, osAxis)" class="text-xs text-red-600" role="alert" style="display:none"></p>
                @php $rxErrors = collect(['sph', 'cyl', 'add'])->crossJoin(['od', 'os'])->map(fn ($pair) => "rx_{$pair[1]}_{$pair[0]}")->filter(fn ($key) => $errors->has($key)); @endphp
                @if($rxErrors->isNotEmpty())
                    <ul class="text-xs text-red-600 space-y-0.5" role="alert">
                        @foreach($rxErrors as $key)<li>{{ $errors->first($key) }}</li>@endforeach
                    </ul>
                @endif
            </div>

            @if($work_type === 'prescription')
            <div class="border-t border-slate-200 pt-4 space-y-3" x-data="{ fulfilment: @js($lens_fulfilment_source) }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-slate-900 uppercase">Lenses</h3>
                    {{-- One choice, three ways to supply the lenses. --}}
                    <div role="radiogroup" aria-label="Lens fulfilment" class="inline-flex rounded-lg border border-slate-300 bg-slate-100 p-0.5 text-xs font-semibold">
                        @foreach(['stock' => 'Stocked lenses', 'external' => 'Special order', 'customer' => 'Customer supplied'] as $value => $title)
                            <label class="cursor-pointer rounded-md px-3 py-1.5 transition" x-bind:class="fulfilment === @js($value) ? 'bg-white text-teal-800 shadow-sm ring-1 ring-teal-600' : 'text-slate-600 hover:text-slate-900'">
                                <input type="radio" name="lens_fulfilment_source" x-model="fulfilment" value="{{ $value }}" class="sr-only">{{ $title }}
                            </label>
                        @endforeach
                    </div>
                </div>
                @error('lens_fulfilment_source')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                <div x-show="fulfilment === 'stock'" x-cloak class="space-y-2">
                    @php $wantedChoices = $this->wantedLensChoices(); @endphp
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-[14rem] flex-1 sm:max-w-sm">
                            <label for="wanted-lens" class="block text-xs font-bold text-slate-700 mb-1">Lens wanted</label>
                            {{-- Read with the Rx when stock is checked; changing it after a check checks again. --}}
                            <select id="wanted-lens" class="ui-input w-full !py-2 text-xs" onchange="if (document.getElementById('stock-lens-options')) document.getElementById('check-lens-stock')?.click()">
                                <option value="">Any stocked lens</option>
                                @foreach(collect($wantedChoices)->groupBy(fn ($label, $key) => explode('|', $key)[0], true) as $design => $lines)
                                    <optgroup label="{{ $design }}">
                                        @foreach($lines as $key => $label)<option value="{{ $key }}" @selected($wantedLens === $key)>{{ $label }}</option>@endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" id="check-lens-stock" onclick="const button=this; const status=document.getElementById('stock-check-client-status'); const component=window.Livewire?(Livewire.all().find(item=>item.name.includes('optical-order-create'))?.$wire||Livewire.first()):null; if(!component){status.textContent='The stock checker is disconnected. Refresh the page and try again.';status.className='text-xs text-red-600';return;} const value=id=>document.getElementById(id)?.value||''; button.disabled=true; button.textContent='Checking…';status.textContent='';status.className='text-xs text-slate-600'; component.call('checkLensAvailabilityFromClient',{wanted:value('wanted-lens'),od:{sph:value('rx-od-sph'),cyl:value('rx-od-cyl'),axis:value('rx-od-axis'),add:value('rx-od-add')},os:{sph:value('rx-os-sph'),cyl:value('rx-os-cyl'),axis:value('rx-os-axis'),add:value('rx-os-add')}}).catch(()=>{if(status.isConnected){status.textContent='Stock could not be checked. Please try again.';status.className='text-xs text-red-600';}}).finally(()=>{if(button.isConnected){button.disabled=false;button.textContent='Check stock';}})" class="ui-button bg-teal-700 text-white hover:bg-teal-800 text-xs font-semibold !py-2">Check stock</button>
                        <span id="stock-check-client-status" class="text-xs text-slate-500" aria-live="polite"></span>
                    </div>

                    @if($stockLensOptions)
                        <div id="stock-lens-options" class="max-w-3xl space-y-1.5">
                            @foreach($stockLensOptions as $option)
                                @php
                                    $usable = in_array($option['status'] ?? null, ['available', 'partial'], true);
                                    $chosen = $stock_lens_key === $option['key'];
                                @endphp
                                @if($wantedLens !== '' && $loop->first && empty($option['matches']))<p class="text-xs font-semibold text-slate-600">No match for the lens wanted. Alternatives in stock:</p>@endif
                                @if($wantedLens !== '' && ! $loop->first && empty($option['matches']) && ! empty($stockLensOptions[$loop->index - 1]['matches']))<p class="pt-1 text-xs font-semibold text-slate-600">Alternatives in stock</p>@endif
                                <label wire:key="stock-lens-{{ md5($option['key']) }}" class="block rounded-lg border px-3 py-2 text-xs {{ $usable ? 'cursor-pointer' : 'opacity-60' }} {{ $chosen ? 'border-teal-600 bg-teal-50 ring-1 ring-teal-600' : 'border-slate-200 bg-white' }}">
                                    <span class="grid grid-cols-[auto_1fr_auto] sm:grid-cols-[auto_1fr_auto_auto_auto] items-center gap-3">
                                        <input type="radio" name="stock_lens_key" wire:click="selectStockLensOption(@js($option['key']))" @checked($chosen) @disabled(! $usable)>
                                        <span class="flex flex-wrap items-center gap-1.5"><strong>{{ $option['design'] }}</strong>
                                            <span class="rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $option['lens_type'] }}</span>
                                            @if($option['coating'] !== '')<span class="rounded bg-teal-100 px-1.5 py-0.5 text-[10px] font-semibold text-teal-900">{{ $option['coating'] }}</span>@endif
                                            @if($option['index'] !== '')<span class="text-[10px] text-slate-500">{{ $option['index'] }}</span>@endif
                                            @if(($option['status'] ?? null) === 'partial')<span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">Half pair in stock</span>@endif
                                        </span>
                                        <span class="font-mono text-teal-800">{{ currency() }} {{ number_format($chosen ? (float) $lens_price : $option['price'], 2) }}</span>
                                        @foreach(['od', 'os'] as $eye)
                                            @php $line = $option['eyes'][$eye] ?? null; $stocked = $line ? $line['source'] === 'stock' : $option[$eye.'_quantity'] > 0; @endphp
                                            <span class="{{ $stocked ? 'text-emerald-700' : 'text-amber-700' }}" @if(! empty($line['reason'])) title="{{ $line['reason'] }}" @endif>{{ strtoupper($eye) }}: {{ $stocked ? 'in stock ('.$option[$eye.'_quantity'].')' : 'special order' }}</span>
                                        @endforeach
                                    </span>
                                </label>
                                {{-- The chosen lens: what each eye will be. A special-order eye has no stock price, so staff price it here. --}}
                                @if($chosen && in_array($lensAvailability['status'] ?? null, ['available', 'partial'], true))
                                    @php
                                        $eyes = $lensAvailability['eyes'] ?? [];
                                        $ordered = collect($eyes)->where('source', 'special_order')->keys();
                                        $mixedMultifocal = $ordered->isNotEmpty() && $ordered->count() < 2 && \App\Support\LensDesign::isEyeSpecific($option['lens_type']);
                                    @endphp
                                    <div class="-mt-1 space-y-2 rounded-b-lg border border-t-0 border-teal-600 bg-white px-3 py-2 text-xs">
                                        @foreach($eyes as $eye => $availability)
                                            @php $special = ($availability['source'] ?? 'stock') === 'special_order'; @endphp
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <strong class="w-7">{{ strtoupper($eye) }}</strong>
                                                <span class="font-mono">SPH {{ sprintf('%+.2f', $availability['sphere']) }} {{ ($availability['power_type'] ?? 'cyl') === 'add' ? 'ADD' : 'CYL' }} {{ sprintf('%+.2f', $availability['cylinder']) }}</span>
                                                @if($special)
                                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">Order from supplier</span>
                                                    @if(! empty($availability['reason']))<span class="text-amber-800">{{ $availability['reason'] }}</span>
                                                    @elseif(($availability['list_price'] ?? null) === null)<span class="text-amber-800">No price for this power</span>
                                                    @else<span class="text-slate-500">Catalogue {{ currency() }} {{ number_format($availability['list_price'], 2) }}</span>@endif
                                                    <label class="ml-auto flex items-center gap-1.5">
                                                        <span class="font-semibold">Price {{ currency() }}</span>
                                                        <input type="number" min="0" step="0.01" wire:model.blur="specialPrices.{{ $eye }}" placeholder="Required" aria-label="{{ strtoupper($eye) }} special-order lens price"
                                                            class="ui-input !w-28 !py-1 text-right font-mono text-xs @error('specialPrices.'.$eye) border-red-500 @enderror">
                                                    </label>
                                                @else
                                                    <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800">From stock · held</span>
                                                    <span class="ml-auto font-mono">{{ currency() }} {{ number_format($availability['unit_price'] ?? 0, 2) }}</span>
                                                @endif
                                            </div>
                                            @error('specialPrices.'.$eye)<p class="text-red-600">{{ $message }}</p>@enderror
                                        @endforeach
                                        @if($ordered->isNotEmpty())
                                            <p class="text-slate-500">Glazing waits until the {{ $ordered->map(fn ($eye) => strtoupper($eye))->implode(' and ') }} lens arrives and is marked received.@if(collect($eyes)->contains(fn ($e) => ($e['source'] ?? '') === 'special_order' && ($e['list_price'] ?? null) === null)) A price entered by hand is emailed to the owner.@endif</p>
                                            @if($leadTimeNote !== '')<p class="font-semibold text-slate-700"><i class="fas fa-calendar-day mr-1 text-slate-400" aria-hidden="true"></i>{{ $leadTimeNote }}</p>@endif
                                        @else
                                            <p class="text-slate-500">Both lenses held for this order when it is placed; taken from stock when glazing starts.</p>
                                        @endif
                                        @if($mixedMultifocal)
                                            <div class="flex flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-2 py-1.5 text-amber-900" role="note">
                                                <span class="flex-1"><strong>Mixed pair:</strong> one stock {{ strtolower($option['lens_type']) }} lens and one made by the supplier. Labs usually advise making both eyes together so the two lenses match.</span>
                                                <button type="button" x-on:click="fulfilment = 'external'" wire:click="specialOrderBothEyes" class="ui-button ui-button-secondary !py-1 text-xs">Special-order both eyes</button>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    {{-- Nothing chosen: why (no stock, wrong lens for the Rx, or the wanted lens is out). --}}
                    @if($lensAvailability && ! in_array($lensAvailability['status'] ?? null, ['available', 'partial'], true))
                        <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900" role="status">{{ $lensAvailability['message'] ?? '' }}</p>
                    @endif
                    @error('stock_lens_key')<p class="text-xs text-red-600">Select one stocked lens option.</p>@enderror
                    @error('measurements')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div x-show="fulfilment === 'external'" x-cloak class="max-w-sm">@if($specialOrderLens !== '')<p class="mb-2 rounded-md bg-amber-50 px-2 py-1.5 text-xs text-amber-900">Both eyes will be special-ordered as <strong>{{ $specialOrderLens }}</strong>. The stock lens stays on the shelf.@if($leadTimeNote !== '') {{ $leadTimeNote }}@endif</p>@endif<label for="special-order-lens-price" class="block text-xs font-bold text-slate-700 mb-1">Special-order price for both lenses ({{ currency() }})</label><input autocomplete="off" id="special-order-lens-price" type="number" min="0" step="0.01" value="{{ $lens_price }}" class="ui-input w-full !py-2 text-xs font-mono"></div>
                <p x-show="fulfilment === 'customer'" x-cloak class="text-xs text-slate-600">No lens charge. Fitting or glazing fees can be added in Pricing.</p>
                @error('lens_stock')<p class="text-xs text-red-600" role="alert">{{ $message }}</p>@enderror
            </div>
            @endif

        <!-- STEP 3: FRAME -->
        @elseif($currentStep === 3)
            <div class="border-b border-slate-200 pb-3">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">3. Frame Selection & Pricing</h2>
                <p class="text-xs text-slate-500">Choose a stock frame, record a customer-supplied frame, or enter a custom frame.</p>
            </div>
            <div class="space-y-4 max-w-3xl" x-data="{ source: @js($frame_source), frameName: @js((string) $frame_model_number), framePrice: @js((string) $frame_price), changeSource() { $wire.set('frame_product_id', null, false); $wire.set('frame_optical_product_id', null, false); this.frameName = ''; this.framePrice = '0'; $wire.set('frame_model_number', '', false); $wire.set('frame_price', 0, false); } }">
                <fieldset>
                    <legend class="block text-xs font-bold text-slate-700 mb-2">Frame source *</legend>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3 cursor-pointer"><input type="radio" value="stock" wire:model="frame_source" x-model="source" x-on:change="changeSource()"> Optical branch stock</label>
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3 cursor-pointer"><input type="radio" value="customer" wire:model="frame_source" x-model="source" x-on:change="changeSource()"> Customer's own frame</label>
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3 cursor-pointer"><input type="radio" value="custom" wire:model="frame_source" x-model="source" x-on:change="changeSource()"> Custom / special order</label>
                    </div>
                </fieldset>
                <div x-show="source === 'stock'" style="{{ $frame_source === 'stock' ? '' : 'display:none' }}">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Stock Frame *</label>
                    <select wire:change="selectFrameOpticalProduct($event.target.value)" x-on:change="const option = $event.target.selectedOptions[0]; frameName = option.dataset.name || ''; framePrice = option.dataset.price || '0'; $wire.set('frame_model_number', frameName, false); $wire.set('frame_price', framePrice, false)" class="ui-input w-full text-xs bg-white">
                        <option value="">Choose a stock frame</option>
                        @foreach($frames as $frame)
                            <option value="{{ $frame->id }}" data-name="{{ $frame->name }}" data-price="{{ $frame->selling_price }}" @selected((int) $frame_optical_product_id === $frame->id)>{{ $frame->sku }} · {{ $frame->name }} · {{ currency() }} {{ number_format($frame->selling_price, 2) }} ({{ $frame->stocks->first()?->quantity ?? 0 }} at branch)</option>
                        @endforeach
                    </select>
                    @if($frame_product_id)<p class="text-xs text-amber-700 mt-1">This quotation uses a legacy clinic frame product. Select an optical SKU to replace it.</p>@endif
                    @error('frame_product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('frame_optical_product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div x-show="source === 'customer'" style="{{ $frame_source === 'customer' ? '' : 'display:none' }}" class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-xs text-teal-900">
                    The customer supplies the frame. Frame charge is {{ currency() }} 0.00, and no frame stock will be used. Add a glazing fee in Pricing if applicable.
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 mb-1" x-text="source === 'customer' ? 'Customer frame description (optional)' : 'Frame Brand & Model Number *'"></label>
                        <input autocomplete="off" type="text" wire:model="frame_model_number" x-model="frameName" x-bind:required="source !== 'customer'" x-bind:placeholder="source === 'customer' ? 'e.g. Black full-rim frame supplied by customer' : 'e.g. Ray-Ban RB3025 Aviator Gold'" class="ui-input w-full text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Frame Structure</label>
                        <select wire:model="frame_structure" class="ui-input w-full text-xs bg-white">
                            <option value="Full Frame">Full Frame</option>
                            <option value="Half-Rim / Supra">Half-Rim / Supra</option>
                            <option value="Rimless / 3-Piece Drill">Rimless / 3-Piece Drill</option>
                        </select>
                    </div>
                    <div x-show="source !== 'customer'" style="{{ $frame_source === 'customer' ? 'display:none' : '' }}">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Frame Price ({{ currency() }}) *</label>
                        <input autocomplete="off" type="number" min="0" step="0.01" wire:model="frame_price" x-model="framePrice" @readonly($frame_optical_product_id) class="ui-input w-full text-xs font-mono font-bold text-teal-800">
                    </div>
                </div>
            </div>

        <!-- STEP 4: FITTING & INSTRUCTIONS -->
        @elseif($currentStep === 4)
            <div class="border-b border-slate-200 pb-3">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">4. Fitting Measurements & Instructions</h2>
            </div>
            
            <div class="space-y-6">
                {{-- Measured with the chosen frame. Saved prescriptions keep their powers; these come from fitting. --}}
                <div class="max-w-2xl overflow-x-auto">
                    <table class="w-full border-collapse text-center text-xs">
                        <thead>
                            <tr class="bg-slate-900 text-[10px] font-semibold uppercase tracking-wider text-white">
                                <th class="border border-slate-800 p-2">Eye</th>
                                <th class="border border-slate-800 p-2">Monocular PD (mm)</th>
                                <th class="border border-slate-800 p-2">Fitting height (mm)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(['od' => 'Right (OD)', 'os' => 'Left (OS)'] as $eye => $label)
                                <tr class="bg-slate-50">
                                    <th scope="row" class="border border-slate-200 p-2 font-bold text-teal-800">{{ $label }}</th>
                                    <td class="border border-slate-200 p-1"><input autocomplete="off" id="fit-{{ $eye }}-pd" type="text" inputmode="decimal" wire:model="rx_{{ $eye }}_pd" placeholder="{{ $eye === 'od' ? '31.5' : '31.0' }}" aria-label="{{ $label }} monocular PD" class="w-full rounded border py-1 text-center font-mono text-xs @error('rx_'.$eye.'_pd') border-red-500 @enderror"></td>
                                    <td class="border border-slate-200 p-1"><input autocomplete="off" id="fit-{{ $eye }}-hgt" type="text" inputmode="decimal" wire:model="rx_{{ $eye }}_hgt" placeholder="19.0" aria-label="{{ $label }} fitting height" class="w-full rounded border py-1 text-center font-mono text-xs @error('rx_'.$eye.'_hgt') border-red-500 @enderror"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="max-w-xs">
                    <label for="fit-segment-height" class="block text-xs font-semibold text-slate-700 mb-1">Segment height (bifocals)</label>
                    <div class="relative">
                        <input autocomplete="off" id="fit-segment-height" type="text" inputmode="decimal" wire:model="segment_height" placeholder="18.5" class="ui-input w-full text-xs font-medium pr-10">
                        <span class="absolute right-3 top-2.5 text-xs text-slate-400 font-sans pointer-events-none">mm</span>
                    </div>
                </div>
                @php $fitErrors = collect(['rx_od_pd', 'rx_os_pd', 'rx_od_hgt', 'rx_os_hgt', 'segment_height'])->filter(fn ($key) => $errors->has($key)); @endphp
                @if($fitErrors->isNotEmpty())
                    <ul class="text-xs text-red-600 space-y-0.5" role="alert">@foreach($fitErrors as $key)<li>{{ $errors->first($key) }}</li>@endforeach</ul>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Expected Ready / Collection Date</label>
                        <input autocomplete="off" type="date" wire:model="pickUpDate" class="ui-input w-full text-xs bg-white">
                        @if($leadTimeNote !== '')<p class="mt-1 text-[11px] text-slate-500">{{ $leadTimeNote }}</p>@endif
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Glazing / Workshop Instructions</label>
                        <input autocomplete="off" type="text" wire:model="lab_instructions" placeholder="e.g. High index bevel, thin edge required" class="ui-input w-full text-xs">
                    </div>
                </div>
            </div>

        <!-- STEP 5: SERVICES & PRICING -->
        @elseif($currentStep === 5)
            @php $pricing = $this->priceBreakdown(); @endphp
            <div class="border-b border-slate-200 pb-3">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">5. Services &amp; Pricing</h2>
                <p class="text-xs text-slate-500">Add optical services such as glazing or tinting. Prices come from the optical service catalogue.</p>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                <div class="lg:col-span-3 space-y-3">
                    @if($work_type === 'prescription')
                        <div x-data="{ query: '', open: false,
                                services: @js($serviceCatalogue->map(fn ($service) => ['id' => $service->id, 'name' => $service->name, 'price' => (float) $service->price])->values()),
                                get selected() { return (this.$wire.service_lines || []).map(line => Number(line.service_id)); },
                                get matches() { const term = this.query.toLowerCase().trim(); return this.services.filter(service => ! this.selected.includes(service.id) && (term === '' || service.name.toLowerCase().includes(term))); } }"
                            x-on:click.outside="open = false" class="relative">
                            <label for="service-search" class="block text-xs font-bold text-slate-700 mb-1">Add services</label>
                            <input id="service-search" type="search" x-model="query" x-on:focus="open = true" x-on:input="open = true" x-on:keydown.escape="open = false"
                                placeholder="Search services, e.g. glazing" autocomplete="off" role="combobox" x-bind:aria-expanded="open" aria-controls="service-options" class="ui-input w-full text-xs">
                            <div id="service-options" x-show="open" x-cloak role="listbox" class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                                <template x-for="service in matches" :key="service.id">
                                    <button type="button" role="option" x-on:click="$wire.addService(service.id); query = ''; open = false"
                                        class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 text-left text-xs hover:bg-teal-50">
                                        <span class="font-semibold" x-text="service.name"></span>
                                        <span class="font-mono text-teal-800" x-text="'{{ currency() }} ' + service.price.toFixed(2)"></span>
                                    </button>
                                </template>
                                <p x-show="matches.length === 0" class="px-3 py-2 text-xs text-slate-500">{{ $serviceCatalogue->isEmpty() ? 'No active services. A manager can add them in Optical Services & Prices.' : 'No matching service, or it is already added.' }}</p>
                            </div>
                        </div>
                    @else
                        <p class="text-xs text-slate-500">Services for this job were chosen in step 1.</p>
                    @endif

                    @if($service_lines)
                        <div class="rounded-lg border border-slate-200 divide-y divide-slate-100">
                            @foreach($service_lines as $index => $line)
                                @php $selectedService = $serviceCatalogue->firstWhere('id', (int) ($line['service_id'] ?? 0)); @endphp
                                @if($selectedService)
                                    <div wire:key="priced-service-{{ $selectedService->id }}" class="flex flex-wrap items-center justify-between gap-3 p-3 text-xs">
                                        <div><strong>{{ $selectedService->name }}</strong><span class="ml-2 font-mono text-slate-500">{{ currency() }} {{ number_format((float) $selectedService->price, 2) }} each</span></div>
                                        <div class="flex items-center gap-3">
                                            <label>Qty <input autocomplete="off" type="number" min="1" max="1000" step="1" wire:model="service_lines.{{ $index }}.quantity" class="ui-input w-20 text-xs ml-1"></label>
                                            @if($work_type === 'prescription')<button type="button" wire:click="removeServiceLine({{ $index }})" class="font-semibold text-red-700 underline">Remove</button>@endif
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @elseif($work_type === 'prescription')
                        <p class="text-xs text-slate-500">No services added.</p>
                    @endif
                    @error('service_lines')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @foreach($errors->get('service_lines.*') as $messages)<p class="text-xs text-red-600">{{ $messages[0] }}</p>@break @endforeach

                    <div class="max-w-xs">
                        <label for="discount-amount" class="block text-xs font-bold text-slate-700 mb-1">Discount Amount ({{ currency() }})</label>
                        <input autocomplete="off" id="discount-amount" type="number" min="0" step="0.01" wire:model="discount_amount" class="ui-input w-full text-xs font-mono text-red-600">
                        @error('discount_amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="lg:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs">
                    <h3 class="mb-2 font-bold uppercase text-slate-600">Order price</h3>
                    @include('livewire.optical.partials.order-price-breakdown', ['pricing' => $pricing])
                </div>
            </div>

        <!-- STEP 6: DEPOSIT -->
        @elseif($currentStep === 6)
            <div class="border-b border-slate-200 pb-3">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">6. Initial Deposit & Payment Method</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-3xl">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Deposit Paid Amount ({{ currency() }}) *</label>
                    <input autocomplete="off" type="number" min="0" step="0.01" wire:model="paid_amount" class="ui-input w-full text-xs font-mono font-bold text-teal-800">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Payment Method</label>
                    <select wire:model="payment_method" class="ui-input w-full text-xs bg-white">
                        @foreach(\App\Support\PaymentMethods::active(\App\Support\PaymentMethods::OPTICAL) as $methodKey => $methodLabel)
                            <option value="{{ $methodKey }}">{{ $methodLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg flex flex-col justify-center">
                    <span class="text-xs text-slate-500 font-semibold uppercase">Balance Due on Collection</span>
                    @php $orderTotal = $this->calculateTotalProperty(); @endphp{{-- The deposit is taken off in the browser as it is typed. --}}<span class="text-lg font-bold font-mono text-red-600" wire:key="balance-{{ $orderTotal }}" x-data="{ total: {{ (float) $orderTotal }} }" x-text="@js(currency()) + ' ' + money(Math.max(0, total - num($wire.paid_amount)))">{{ currency() }} {{ number_format($this->calculateBalanceProperty(), 2) }}</span>
                </div>
            </div>

        <!-- STEP 7: REVIEW -->
        @elseif($currentStep === 7)
            <div class="border-b border-slate-200 pb-3">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">7. Order Review & Final Confirmation</h2>
                <p class="text-xs text-slate-500">Review all details before submitting or saving as quotation.</p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs space-y-3 max-w-3xl">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p><strong>Source:</strong> {{ ucfirst(str_replace('_', ' ', $order_source)) }}</p>
                        <p><strong>Wearer / Customer:</strong> {{ $customer_name ?: ($partner_clinic_name ?: 'Not selected') }} ({{ $customer_phone ?: 'N/A' }})</p>
                        <p><strong>Reference:</strong> {{ $reference ?: '—' }}</p>
                        <p><strong>Work:</strong> {{ $work_type === 'service' ? 'Optical service' : 'Prescription lenses' }}</p>
                        @if($bill_to === 'partner')<p><strong>Partner clinic:</strong> {{ $partner_clinic_name }}</p>@endif
                        @if($work_type === 'service')@foreach($service_lines as $line)@php $reviewService = \App\Models\OpticalService::find((int) ($line['service_id'] ?? 0)); @endphp@if($reviewService)<p><strong>Service:</strong> {{ $reviewService->name }} × {{ $line['quantity'] ?? 1 }}</p>@endif @endforeach @endif
                        @if($frame_model_number)<p><strong>Frame:</strong> {{ $frame_model_number }} ({{ $frame_structure }})</p>@endif
                        @if($work_type === 'prescription')<p><strong>Lenses:</strong> {{ $lens_type }} {{ $lens_index }} ({{ $base_color }})</p>@if($optical_category_id)<p><strong>Category:</strong> {{ $opticalCategories->firstWhere('id', (int) $optical_category_id)?->name ?? 'Previously selected' }}</p>@endif @endif
                        @if($work_type === 'prescription')<p><strong>Lens fulfilment:</strong> {{ ['stock' => 'Branch lens stock', 'catalogue' => 'Catalogued lens SKU', 'external' => 'Special order', 'customer' => 'Customer supplied lenses'][$lens_fulfilment_source] ?? 'Special order' }}</p>
                            @if($lens_fulfilment_source === 'stock')@foreach(($lensAvailability['eyes'] ?? []) as $eye => $line)<p class="pl-3">{{ strtoupper($eye) }}: {{ ($line['source'] ?? 'stock') === 'stock' ? 'branch stock' : 'special order' }} · {{ currency() }} {{ number_format($line['unit_price'] ?? 0, 2) }}</p>@endforeach @endif
                        @endif
                    </div>
                    <div>
                        @include('livewire.optical.partials.order-price-breakdown', ['pricing' => $this->priceBreakdown()])
                        <p><strong>Deposit Paid:</strong> <span class="font-bold text-emerald-600 font-mono">{{ currency() }} {{ number_format((float)$paid_amount, 2) }}</span></p>
                        <p><strong>Balance Due:</strong> <span class="font-bold text-red-600 font-mono">{{ currency() }} {{ number_format($this->calculateBalanceProperty(), 2) }}</span></p>
                        <p><strong>Expected Ready Date:</strong> {{ $pickUpDate }}</p>
                    </div>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Additional Internal Notes</label>
                    <textarea wire:model="notes" rows="2" placeholder="Any special notes..." class="ui-input w-full text-xs"></textarea>
                </div>
            </div>
        @endif

        <!-- Wizard navigation: stays at the bottom of the screen while the step scrolls. -->
        <div class="sticky bottom-0 z-20 -mx-6 -mb-6 flex flex-wrap items-center justify-between gap-3 rounded-b-xl border-t border-slate-200 bg-white/95 px-6 py-3 shadow-[0_-4px_12px_rgba(15,23,42,0.06)] backdrop-blur">
            <button type="button" wire:click="prevStep" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait"
                class="ui-button bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-xs px-4 py-2 border border-slate-300 rounded-lg shadow-sm"
                {{ $currentStep === 1 ? 'disabled' : '' }}>
                <span wire:loading.remove wire:target="prevStep">← Back: {{ $steps[$previousStep] ?? 'Previous' }}</span>
                <span wire:loading wire:target="prevStep">Loading…</span>
            </button>

            <div class="flex flex-wrap items-center gap-3">
                @if($currentStep > 1)
                    @php $runningTotal = $this->calculateTotalProperty(); @endphp
                    @if($runningTotal > 0)<span class="text-xs text-slate-600">Total <strong class="font-mono text-slate-900">{{ currency() }} {{ number_format($runningTotal, 2) }}</strong></span>@endif
                @endif
                @unless($remakeOfId)
                <button type="button" wire:click="saveAsQuotation" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait"
                    class="ui-button bg-slate-200 hover:bg-slate-300 text-slate-800 font-semibold text-xs px-4 py-2 rounded-lg border border-slate-300 shadow-sm">
                    <span wire:loading.remove wire:target="saveAsQuotation">{{ $editingQuotationId ? 'Update Quotation' : 'Save as Quotation' }}</span>
                    <span wire:loading wire:target="saveAsQuotation">Saving…</span>
                </button>
                @endunless

                @if($currentStep < 7)
                    <button type="button"
                        @if($currentStep === 1)
                            onclick="const button=this; const status=document.getElementById('next-step-client-status'); const component=window.Livewire?(Livewire.all().find(item=>item.name.includes('optical-order-create'))?.$wire||Livewire.first()):null; if(!component){status.textContent='The order form is disconnected. Refresh the page and try again.';return;} const requester={order_source:document.getElementById('order-source')?.value,work_type:document.getElementById('order-work-type')?.value,bill_to:document.getElementById('order-bill-to')?.value||'customer',partner_id:document.getElementById('selected-partner-id')?.value||null,customer_name:document.getElementById('order-customer-name')?.value||'',customer_phone:document.getElementById('order-customer-phone')?.value||'',reference:document.getElementById('order-reference')?.value||''}; button.disabled=true; status.textContent='Validating…'; component.call('nextStepWithRequester', requester).catch(()=>{if(status.isConnected)status.textContent='Unable to continue. Review the highlighted fields and try again.';}).finally(() => { if(button.isConnected) button.disabled=false; });"
                        @elseif($currentStep === 2)
                            onclick="const button=this; const status=document.getElementById('next-step-client-status'); const component=window.Livewire?(Livewire.all().find(item=>item.name.includes('optical-order-create'))?.$wire||Livewire.first()):null; if(!component){status.textContent='The order form is disconnected. Refresh the page and try again.';return;} const value=id=>document.getElementById(id)?.value||''; const fulfilment=document.querySelector('input[name=lens_fulfilment_source]:checked')?.value||''; const lens={fulfilment,lens_price:value('special-order-lens-price'),od:{sph:value('rx-od-sph'),cyl:value('rx-od-cyl'),axis:value('rx-od-axis'),add:value('rx-od-add')},os:{sph:value('rx-os-sph'),cyl:value('rx-os-cyl'),axis:value('rx-os-axis'),add:value('rx-os-add')}}; button.disabled=true; status.textContent='Validating…'; component.call('nextStepWithLens',lens).catch(()=>{if(status.isConnected)status.textContent='Unable to continue. Review the highlighted fields and try again.';}).finally(()=>{if(button.isConnected)button.disabled=false;});"
                        @else
                            onclick="const button=this; const status=document.getElementById('next-step-client-status'); const component=window.Livewire?(Livewire.all().find(item=>item.name.includes('optical-order-create'))?.$wire||Livewire.first()):null; if(!component){status.textContent='The order form is disconnected. Refresh the page and try again.';return;} button.disabled=true; status.textContent='Validating…'; component.call('nextStep').catch(()=>{if(status.isConnected)status.textContent='Unable to continue. Review the highlighted fields and try again.';}).finally(() => { if(button.isConnected) button.disabled=false; });"
                        @endif
                        wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait"
                        class="ui-button bg-teal-700 hover:bg-teal-800 text-white font-semibold text-xs px-5 py-2 rounded-lg shadow-sm">
                        @php $nextAction = $currentStep === 1 ? 'nextStepWithRequester' : ($currentStep === 2 ? 'nextStepWithLens' : 'nextStep'); @endphp
                        <span wire:loading.remove wire:target="{{ $nextAction }}">Next: {{ $steps[$nextStep] ?? 'Review' }} →</span>
                        <span wire:loading wire:target="{{ $nextAction }}">Checking…</span>
                    </button>
                    <span id="next-step-client-status" class="text-xs font-medium text-red-600" aria-live="polite"></span>
                @else
                    <button type="button" wire:click="createOrder" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait"
                        class="ui-button bg-teal-800 hover:bg-teal-900 text-white font-bold text-xs px-6 py-2 rounded-lg shadow font-mono">
                        <span wire:loading.remove wire:target="createOrder">Place Optical Order ✓</span>
                        <span wire:loading wire:target="createOrder">Placing order…</span>
                    </button>
                @endif
            </div>
        </div>

    </div>
</div>

