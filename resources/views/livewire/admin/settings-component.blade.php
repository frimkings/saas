<div class="clinic-ui ui-page">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-teal-700 font-semibold mb-0">Clinic Profile &amp; Branding</h2>
            <p class="text-slate-500 text-sm uppercase font-semibold mb-0">Manage the details displayed on clinic documents</p>
        </div>
        <div class="inline-flex flex-wrap gap-1 shadow-sm">
            <button type="button" wire:click="discardChanges" class="btn ui-button ui-button-secondary">
                <i class="fas fa-undo mr-1"></i> Discard Changes
            </button>
        </div>
    </div>

    <p class="text-slate-500 mb-4"><strong>Active clinic:</strong> {{ $activeClinic?->name ?? $setting->clinic_name }}<br><small>Changes apply to this clinic across its branches.</small></p>

    @if (!empty($missingSetupFields))
        <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 border-0 shadow-sm">
            <div class="font-semibold mb-1">
                <i class="fas fa-info-circle mr-2"></i>Complete your clinic profile:
            </div>
            <div>Your clinic name is assigned during registration. Address, contact number, email, and logo are optional. Replace or clear any example details and save to finish setup.</div>
        </div>
    @endif

    <div class="flex flex-wrap -mx-2">
        {{-- Settings Form --}}
        <div class="w-full md:w-6/12 px-2">
            <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg mb-6">
                <div class="card-header border-b border-slate-200 px-4 bg-teal-700 text-white py-4 border-0">
                    <h5 class="mb-0 font-semibold"><i class="fas fa-cogs mr-2"></i> Clinic Information</h5>
                </div>
                <div class="card-body p-4">
                    <form wire:submit="updateSettings">
                        {{-- Clinic Name --}}
                        <div class="mb-4">
                            <label for="registered-clinic-name" class="text-sm font-semibold text-slate-500">REGISTERED CLINIC NAME</label>
                            <input id="registered-clinic-name" type="text" value="{{ $setting->clinic_name }}" class="form-control ui-input bg-slate-50 border-0" readonly>
                            <small class="mt-1 block text-xs text-slate-500">Assigned during registration. Contact the developer to request a name change.</small>
                        </div>

                        {{-- Clinic Address --}}
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500">CLINIC ADDRESS (optional)</label>
                            <textarea wire:model.live.debounce.300ms="state.clinic_address" class="form-control ui-input bg-slate-50 border-0 @error('state.clinic_address') is-invalid @enderror" rows="2" placeholder="e.g., 123 Visionary St., Optic City"></textarea>
                            @error('state.clinic_address') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                        </div>

                        {{-- Clinic Contact --}}
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500">CONTACT NUMBER (optional)</label>
                            <input type="text" wire:model.live.debounce.300ms="state.clinic_contact" class="form-control ui-input bg-slate-50 border-0 @error('state.clinic_contact') is-invalid @enderror" placeholder="e.g., +123 456 7890">
                            @error('state.clinic_contact') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                        </div>

                        {{-- Clinic Email --}}
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500">EMAIL ADDRESS (optional)</label>
                            <input type="email" wire:model.live.debounce.300ms="state.clinic_email" class="form-control ui-input bg-slate-50 border-0 @error('state.clinic_email') is-invalid @enderror" placeholder="e.g., info@brightsight.com">
                            @error('state.clinic_email') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">Leave blank if the clinic does not use an email address.</small>
                        </div>

                        {{-- Logo Upload --}}
                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500">CLINIC LOGO</label>
                            <div class="block">
                                <input type="file" wire:model.live="newLogo" class="block w-full text-sm @error('newLogo') is-invalid @enderror" id="customFile{{ $uploadInputKey }}" wire:key="clinic-logo-{{ $uploadInputKey }}">
                                <label class="hidden" for="customFile{{ $uploadInputKey }}">{{ $newLogo ? $newLogo->getClientOriginalName() : 'Choose file...' }}</label>
                            </div>
                            @error('newLogo') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">Max size: 2MB. Recommended: PNG, transparent background.</small>
                            <div wire:loading wire:target="newLogo" class="text-sky-700 text-sm mt-2">Uploading...</div>
                        </div>

                        @if ($currentLogo)
                            <button type="button" wire:click="removeLogo" class="btn ui-button ui-button-danger ui-button-sm">
                                <i class="fas fa-trash mr-1"></i> Remove Current Logo
                            </button>
                        @endif

                        <hr class="my-6">

                        <button type="submit" class="btn ui-button ui-button-primary w-full py-2 font-semibold shadow-sm mt-6">
                            <i class="fas fa-save mr-2"></i> SAVE SETTINGS
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Live Preview --}}
        <div class="w-full md:w-6/12 px-2">
            <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg mb-6">
                <div class="card-header border-b border-slate-200 px-4 bg-sky-600 text-white py-4 border-0">
                    <h5 class="mb-0 font-semibold"><i class="fas fa-eye mr-2"></i> Live Preview</h5>
                </div>
                <div class="card-body text-center p-6">
                    <p class="text-sm text-slate-500 mb-4">This is how your logo and clinic name will appear.</p>
                    
                    <div class="mb-6 p-4 border border-slate-200 rounded-md" style="background-color: #f8f9fa;">
                        {{-- Logo Preview --}}
                        @if ($newLogo)
                            <img src="{{ $newLogo->temporaryUrl() }}" class="h-auto max-w-full rounded-md shadow-sm" style="max-height: 120px; max-width: 100%; object-fit: contain;" alt="New Logo Preview">
                        @elseif ($currentLogo)
                            <img src="{{ asset('storage/' . $currentLogo) }}" class="h-auto max-w-full rounded-md shadow-sm" style="max-height: 120px; max-width: 100%; object-fit: contain;" alt="Current Clinic Logo"
                                 onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden'); this.nextElementSibling.classList.add('flex');">
                            {{-- Shown if the saved logo's file is gone (e.g. a copied database without its uploads). --}}
                            <div class="hidden text-slate-500 flex-col items-center justify-center p-6 border rounded-md border-amber-400" style="min-height: 120px;">
                                <i class="fas fa-image fa-2x mb-2 text-amber-600"></i>
                                <span class="text-sm font-semibold">Logo file missing — please upload it again</span>
                            </div>
                        @else
                            <div class="text-slate-500 flex flex-col items-center justify-center p-6 border border-slate-200 rounded-md" style="min-height: 120px;">
                                <i class="fas fa-clinic-medical fa-3x mb-2 text-teal-700"></i>
                                <span class="text-sm font-semibold">No Logo Uploaded</span>
                            </div>
                        @endif
                    </div>

                    {{-- Clinic Name Preview --}}
                    <h4 class="text-teal-700 font-semibold mb-2">{{ $setting->clinic_name }}</h4>
                    @if (trim($state['clinic_address']))
                        <p class="text-slate-500 mb-1">{{ $state['clinic_address'] }}</p>
                    @endif
                    @if (trim($state['clinic_contact']))
                        <p class="text-slate-500 mb-1">Contact: {{ $state['clinic_contact'] }}</p>
                    @endif
                    @if (trim($state['clinic_email']))
                        <p class="text-slate-500 mb-1">Email: {{ $state['clinic_email'] }}</p>
                    @endif

                    <small class="text-slate-500 mt-4 block">Save changes to update clinic details on receipts, prescriptions, letters, and reports.</small>
                </div>
            </div>

            {{-- Regional & clinical preferences (saved with SAVE SETTINGS) --}}
            <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg">
                <div class="card-body p-6">
                    <h5 class="font-semibold mb-4"><i class="fas fa-globe-africa mr-2 text-teal-700"></i>Regional &amp; Clinical Preferences</h5>
    {{-- Currency --}}
    <div class="mb-4">
        <label class="text-sm font-semibold text-slate-500">CURRENCY</label>
        <select wire:model.live="currency_symbol" class="form-control ui-input bg-slate-50 border-0 @error('currency_symbol') is-invalid @enderror">
            @foreach(\App\Models\Setting::CURRENCIES as $symbol => $label)
                <option value="{{ $symbol }}">{{ $label }}</option>
            @endforeach
        </select>
        @error('currency_symbol') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
        <small class="mt-1 block text-xs text-slate-500">Applies to all prices, receipts, and financial reports.</small>
    </div>

    {{-- VA Notation --}}
    <div class="mb-4">
        <label class="text-sm font-semibold text-slate-500">VISUAL ACUITY NOTATION</label>
        <div class="pt-1">
            <div class="flex items-center gap-2 mr-4 inline-flex">
                <input type="radio" wire:model.live="va_notation" value="6m"
                    id="va6m" class="rounded border-slate-300 text-teal-700">
                <label for="va6m" class="">6 metre &nbsp;<span class="text-slate-500">(6/6, 6/12…)</span></label>
            </div>
            <div class="flex items-center gap-2 mr-4 inline-flex">
                <input type="radio" wire:model.live="va_notation" value="20ft"
                    id="va20ft" class="rounded border-slate-300 text-teal-700">
                <label for="va20ft" class="">20 foot &nbsp;<span class="text-slate-500">(20/20, 20/40…)</span></label>
            </div>
        </div>
        <small class="mt-1 block text-xs text-slate-500">Affects all Visual Acuity dropdowns. Previously recorded values are not changed.</small>
    </div>
                    <small class="text-slate-500 block">Saved with <strong>Save Settings</strong>.</small>
                </div>
            </div>
        </div>
    </div>
</div>
