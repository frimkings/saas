<div class="p-6">
    <div class="flex flex-wrap -mx-2">
        {{-- Receipts --}}
        <div class="w-full lg:w-6/12 px-2 mb-6 lg:mb-0">
            <div class="card overflow-hidden border border-slate-200 bg-white border-0 shadow-sm rounded-lg h-full">
                <div class="card-body p-4">
                    <h5 class="font-semibold mb-1"><i class="fas fa-file-invoice mr-1 text-teal-700"></i> Receipts</h5>
                    <p class="text-sm text-slate-500 mb-4">How clinical payments are receipted and texted to patients.</p>

                    <form wire:submit.prevent="save">
                        <div class="mb-4">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" wire:model.live="visit_receipts_enabled" id="visitReceipts" class="rounded border-slate-300 text-teal-700">
                                <label for="visitReceipts" class="font-semibold">One receipt per visit</label>
                            </div>
                            <small class="mt-1 block text-xs text-slate-500">
                                Clinical payments in one visit (clearance, the doctor's prescription and items added at reception) go on one receipt,
                                printed when the patient leaves, instead of a receipt at every payment. Patients get one SMS per visit at closing time
                                (switch on "Visit Receipt" and "Visit Part Payment" in Communications &rarr; Messages). Optical has its own receipts.
                            </small>
                        </div>

                        <div class="mb-4">
                            <label class="text-sm font-semibold text-slate-500" for="closingTime">CLOSING TIME</label>
                            <input type="time" id="closingTime" wire:model="closing_time"
                                   class="form-control ui-input bg-slate-50 border-0 @error('closing_time') is-invalid @enderror" style="max-width:160px;">
                            @error('closing_time') <span class="text-red-700 text-sm">{{ $message }}</span> @enderror
                            <small class="mt-1 block text-xs text-slate-500">When the day's visit SMS go out (clinic time).</small>
                        </div>

                        <button type="submit" class="btn ui-button ui-button-primary ui-button-sm">
                            <i class="fas fa-save mr-1"></i> Save receipt settings
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Payment methods --}}
        <div class="w-full lg:w-6/12 px-2">
            @livewire('admin.payment-methods-component', [], key('payment-methods-clinic'))
        </div>
    </div>
</div>
