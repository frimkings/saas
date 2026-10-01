<div class="p-4">
    <div class="row">
        {{-- Receipts --}}
        <div class="col-lg-6 mb-4 mb-lg-0">
            <div class="card border-0 shadow-sm rounded-lg h-100">
                <div class="card-body">
                    <h5 class="font-weight-bold mb-1"><i class="fas fa-file-invoice mr-1 text-primary"></i> Receipts</h5>
                    <p class="small text-muted mb-3">How clinical payments are receipted and texted to patients.</p>

                    <form wire:submit.prevent="save">
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" wire:model.live="visit_receipts_enabled" id="visitReceipts" class="custom-control-input">
                                <label for="visitReceipts" class="custom-control-label font-weight-bold">One receipt per visit</label>
                            </div>
                            <small class="form-text text-muted">
                                Clinical payments in one visit (clearance, the doctor's prescription and items added at reception) go on one receipt,
                                printed when the patient leaves, instead of a receipt at every payment. Patients get one SMS per visit at closing time
                                (switch on "Visit Receipt" and "Visit Part Payment" in Communications &rarr; Messages). Optical has its own receipts.
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold text-muted" for="closingTime">CLOSING TIME</label>
                            <input type="time" id="closingTime" wire:model="closing_time"
                                   class="form-control bg-light border-0 @error('closing_time') is-invalid @enderror" style="max-width:160px;">
                            @error('closing_time') <span class="text-danger small">{{ $message }}</span> @enderror
                            <small class="form-text text-muted">When the day's visit SMS go out (clinic time).</small>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-save mr-1"></i> Save receipt settings
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Payment methods --}}
        <div class="col-lg-6">
            @livewire('admin.payment-methods-component', [], key('payment-methods-clinic'))
        </div>
    </div>
</div>
