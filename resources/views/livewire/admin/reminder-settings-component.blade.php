<div class="{{ $optical ? 'ui-panel p-6 space-y-4 max-w-2xl' : 'p-4' }}">
    <div class="{{ $optical ? '' : 'card border-0 shadow-sm rounded-lg' }}" style="{{ $optical ? '' : 'max-width:720px' }}">
        <div class="{{ $optical ? 'space-y-4' : 'card-body' }}">
            <h5 class="{{ $optical ? 'text-sm font-semibold text-slate-900' : 'font-weight-bold mb-1' }}"><i class="fas fa-bell {{ $optical ? '' : 'mr-1 text-warning' }}"></i> Reminders</h5>
            <p class="{{ $optical ? 'ui-muted text-xs' : 'small text-muted mb-3' }}">
                When "Needs attention" flags things for staff (dashboard, menu badges and the start-of-shift summary), and when
                uncollected glasses reach the owner's morning email. The same settings apply to the clinic and the optical shop.
            </p>

            <form wire:submit.prevent="save" class="{{ $optical ? 'ui-form space-y-4' : '' }}">
                @foreach([
                    ['appointment_hours', 'Appointments coming up', 'hours ahead', 'Flag appointments starting within this many hours whose patient has not arrived.', 1, 24],
                    ['due_days', 'Spectacles due soon', 'days ahead', 'Flag spectacles promised for today or within this many days that are not ready yet (0 = today only).', 0, 14],
                    ['uncollected_days', 'Ready, not collected', 'days waiting', 'Flag glasses ready for at least this many days and not collected.', 1, 90],
                    ['owner_uncollected_days', "Owner's morning email", 'days waiting', "List glasses not collected after this many days in the owner's morning alerts email, with spectacles past their promised date.", 1, 365],
                    ['stale_days', 'Stop chasing old orders', 'days', 'Orders more than this many days late, or ready this long and not collected, leave the daily list and wait on "Tidy up old orders" to be marked collected.', 7, 365],
                    ['expiry_days', 'Expiring stock', 'days before expiry', 'Flag stock batches this many days before they expire (90 = about 3 months). Expired stock stays on the list until a Super Admin removes it from stock.', 30, 365],
                ] as [$field, $label, $unit, $help, $min, $max])
                    <div class="{{ $optical ? 'ui-field' : 'form-group' }}">
                        <label class="{{ $optical ? '' : 'small font-weight-bold text-muted text-uppercase' }}" for="rem-{{ $field }}">{{ $label }}</label>
                        <div class="{{ $optical ? 'flex items-center gap-2' : 'd-flex align-items-center' }}" style="gap:8px">
                            <input type="number" id="rem-{{ $field }}" wire:model="{{ $field }}" min="{{ $min }}" max="{{ $max }}"
                                   class="{{ $optical ? 'ui-input' : 'form-control bg-light border-0' }} @error($field) is-invalid @enderror" style="max-width:110px">
                            <span class="{{ $optical ? 'text-sm text-slate-600' : 'small text-muted' }}">{{ $unit }}</span>
                        </div>
                        <small class="{{ $optical ? 'ui-muted text-xs' : 'form-text text-muted' }}">{{ $help }}</small>
                        @error($field)<small class="{{ $optical ? 'text-xs text-red-600' : 'text-danger small' }}">{{ $message }}</small>@enderror
                    </div>
                @endforeach

                <button type="submit" class="{{ $optical ? 'ui-button ui-button-primary' : 'btn btn-primary btn-sm' }}">
                    <i class="fas fa-save"></i> Save reminder settings
                </button>
            </form>
        </div>
    </div>
</div>
