<div data-livewire-root>
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="mb-4">
                <h5 class="font-weight-bold mb-1"><i class="fas fa-comment-dots text-primary mr-2"></i> SMS Message Templates</h5>
                <p class="text-muted small mb-0">
                    Customise the message sent for each trigger. Use the placeholder badges to insert dynamic values — they are replaced automatically when the SMS is sent.
                </p>
            </div>

            @foreach($templates as $key => $tpl)
                @php
                    $meta = [
                        'appointment_booking'        => ['fas fa-calendar-check', 'primary',   'Sent automatically when a new appointment is booked.'],
                        'appointment_reminder'       => ['fas fa-bell',           'warning',   'Triggered when clicking the SMS button on an appointment.'],
                        'appointment_auto_reminder'  => ['fas fa-clock',          'warning',   'Sent automatically ~24 hours before each appointment via the scheduler.'],
                        'spectacles_ready'           => ['fas fa-glasses',        'success',   'Sent automatically when an order status changes to Ready.'],
                        'spectacles_reminder'        => ['fas fa-redo',           'info',      'Triggered by the "Send Ready Reminder" button on a spectacles order.'],
                        'payment_receipt'            => ['fas fa-receipt',        'dark',      'Sent automatically after a full payment is processed at POS.'],
                        'birthday_wishes'            => ['fas fa-birthday-cake',  'danger',    'Requires task scheduler — see note below.'],
                        'patient_recall'             => ['fas fa-user-clock',     'purple',    'Sent automatically to patients inactive beyond the configured threshold.'],
                        'custom_broadcast'           => ['fas fa-bullhorn',       'secondary', 'Send manually to selected patients — e.g. Christmas, New Year, Independence Day.'],
                    ];
                    [$icon, $color, $desc] = $meta[$key] ?? ['fas fa-sms', 'secondary', ''];
                @endphp

                <div class="card border-0 shadow-sm rounded-lg mb-4">
                    <div class="card-header border-0 py-3 bg-white d-flex align-items-center">
                        <span class="badge badge-{{ $color }} p-2 mr-3" style="font-size:15px; border-radius:8px; min-width:36px; text-align:center;">
                            <i class="{{ $icon }}"></i>
                        </span>
                        <div>
                            <h6 class="mb-0 font-weight-bold">{{ $tpl['label'] }}</h6>
                            <small class="text-muted">{{ $desc }}</small>
                        </div>
                    </div>

                    <div class="card-body pt-0">
                        {{-- Placeholder chips --}}
                        <div class="mb-2 pt-2">
                            <span class="small font-weight-bold text-muted mr-1">Insert:</span>
                            @foreach($tpl['placeholders'] as $ph)
                                <code class="badge badge-light border mr-1 small" style="cursor:pointer;"
                                      onclick="insertPlaceholder('{{ $key }}', '{{ $ph }}')">{{ $ph }}</code>
                            @endforeach
                        </div>

                        {{-- Textarea --}}
                        <textarea
                            id="tpl-{{ $key }}"
                            wire:model="templates.{{ $key }}.message"
                            rows="3"
                            class="form-control bg-light border-0 @error('templates.'.$key.'.message') is-invalid @enderror"
                            maxlength="1000">{{ $tpl['message'] }}</textarea>

                        @error('templates.'.$key.'.message')
                            <span class="text-danger small d-block mt-1">{{ $message }}</span>
                        @enderror

                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-muted">{{ mb_strlen($tpl['message']) }} / 160 chars per segment</small>
                            <div>
                                <button type="button"
                                        wire:click="discardChanges('{{ $key }}')"
                                        wire:loading.attr="disabled" wire:target="discardChanges('{{ $key }}')"
                                        class="btn btn-sm btn-outline-secondary mr-2">
                                    <i class="fas fa-undo mr-1"></i> Discard
                                </button>
                                <button type="button"
                                        wire:click="save('{{ $key }}')"
                                        wire:loading.attr="disabled" wire:target="save('{{ $key }}')"
                                        class="btn btn-sm btn-primary font-weight-bold shadow-sm">
                                    <span wire:loading.remove wire:target="save('{{ $key }}')">
                                        <i class="fas fa-save mr-1"></i> Save
                                    </span>
                                    <span wire:loading wire:target="save('{{ $key }}')">Saving…</span>
                                </button>
                            </div>
                        </div>

                        {{-- Broadcast panel — only shown for custom_broadcast --}}
                        @if($key === 'custom_broadcast')
                            <hr class="my-3">
                            <p class="font-weight-bold small text-muted mb-2"><i class="fas fa-paper-plane mr-1"></i> Send Broadcast</p>

                            {{-- Recipient filter --}}
                            <div class="mb-3">
                                <label class="small font-weight-bold text-muted d-block mb-1">Send to:</label>

                                <div class="custom-control custom-radio mb-1">
                                    <input type="radio" id="brf_all" name="broadcastFilter" value="all"
                                           class="custom-control-input" wire:model.live="broadcastFilter">
                                    <label class="custom-control-label small" for="brf_all">
                                        <strong>All patients</strong> with a contact number
                                    </label>
                                </div>
                                <div class="custom-control custom-radio mb-1">
                                    <input type="radio" id="brf_year" name="broadcastFilter" value="this_year"
                                           class="custom-control-input" wire:model.live="broadcastFilter">
                                    <label class="custom-control-label small" for="brf_year">
                                        <strong>Active this year</strong> — visited in the current calendar year
                                    </label>
                                </div>
                                <div class="custom-control custom-radio mb-1">
                                    <input type="radio" id="brf_24" name="broadcastFilter" value="last_24_months"
                                           class="custom-control-input" wire:model.live="broadcastFilter">
                                    <label class="custom-control-label small" for="brf_24">
                                        <strong>Active in last 24 months</strong>
                                    </label>
                                </div>
                                <div class="custom-control custom-radio mb-1">
                                    <input type="radio" id="brf_custom" name="broadcastFilter" value="custom"
                                           class="custom-control-input" wire:model.live="broadcastFilter">
                                    <label class="custom-control-label small" for="brf_custom">
                                        <strong>Custom range</strong>
                                    </label>
                                </div>

                                @if($broadcastFilter === 'custom')
                                    <div class="mt-1 ml-4 d-flex align-items-center">
                                        <input type="number"
                                               wire:model="broadcastCustomMonths"
                                               class="form-control form-control-sm bg-light border-0 @error('broadcastCustomMonths') is-invalid @enderror"
                                               style="width:90px;" min="1" max="120" placeholder="e.g. 18">
                                        <span class="ml-2 small text-muted">months back</span>
                                        @error('broadcastCustomMonths')
                                            <span class="text-danger small ml-2">{{ $message }}</span>
                                        @enderror
                                    </div>
                                @endif
                            </div>

                            {{-- Confirm step --}}
                            @if($broadcastConfirmStep)
                                <div class="alert alert-warning border-0 rounded py-2 px-3 small mb-2">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    This will send the message to
                                    <strong>{{ $broadcastRecipientCount }} patient(s)</strong>.
                                    Are you sure?
                                </div>
                                <div class="d-flex">
                                    <button type="button"
                                            wire:click="sendCustomBroadcast"
                                            wire:loading.attr="disabled" wire:target="sendCustomBroadcast"
                                            class="btn btn-sm btn-danger font-weight-bold mr-2">
                                        <span wire:loading.remove wire:target="sendCustomBroadcast">
                                            <i class="fas fa-paper-plane mr-1"></i> Yes, Send Now
                                        </span>
                                        <span wire:loading wire:target="sendCustomBroadcast">Sending…</span>
                                    </button>
                                    <button type="button" wire:click="cancelBroadcast"
                                            class="btn btn-sm btn-outline-secondary">Cancel</button>
                                </div>
                            @else
                                <button type="button"
                                        wire:click="prepareBroadcast"
                                        wire:loading.attr="disabled" wire:target="prepareBroadcast"
                                        class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
                                    <span wire:loading.remove wire:target="prepareBroadcast">
                                        <i class="fas fa-bullhorn mr-1"></i> Preview &amp; Send
                                    </span>
                                    <span wire:loading wire:target="prepareBroadcast">Counting…</span>
                                </button>
                            @endif
                        @endif

                    </div>
                </div>
            @endforeach

            {{-- Appointment auto-reminder scheduler note --}}
            <div class="alert alert-info border-0 shadow-sm small mb-4">
                <i class="fas fa-clock mr-1"></i>
                <strong>Appointment Auto-Reminder:</strong>
                The command <code>php artisan sms:appointment-reminders</code> runs hourly via the scheduler.
                It sends the <em>Appointment Auto-Reminder</em> template to patients whose appointment is
                ~24 hours away and have not yet received a reminder. Ensure your server cron runs
                <code>php artisan schedule:run</code> every minute. Use <code>--dry-run</code> to preview.
            </div>

            {{-- Patient Recall settings --}}
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header border-0 py-3 bg-white d-flex align-items-center">
                    <span class="badge badge-secondary p-2 mr-3" style="font-size:15px; border-radius:8px; min-width:36px; text-align:center; background:#6f42c1 !important;">
                        <i class="fas fa-user-clock"></i>
                    </span>
                    <div>
                        <h6 class="mb-0 font-weight-bold">Patient Recall — Settings</h6>
                        <small class="text-muted">Automatically remind inactive patients to book their next check-up.</small>
                    </div>
                </div>
                <div class="card-body">

                    <div class="d-flex align-items-center mb-3">
                        <div class="custom-control custom-switch mr-3">
                            <input type="checkbox" class="custom-control-input" id="recallEnabled"
                                   wire:model.live="recallEnabled">
                            <label class="custom-control-label font-weight-bold" for="recallEnabled">
                                Enable automated recall SMS
                            </label>
                        </div>
                        @if($recallEnabled)
                            <span class="badge badge-success small">Active</span>
                        @else
                            <span class="badge badge-secondary small">Disabled</span>
                        @endif
                    </div>

                    @if($recallEnabled)
                        <div class="form-group mb-3">
                            <label class="small font-weight-bold text-muted">Send recall when a patient has not visited for:</label>
                            <div class="d-flex align-items-center mt-1">
                                <input type="number"
                                       wire:model="recallMonths"
                                       class="form-control form-control-sm bg-light border-0 @error('recallMonths') is-invalid @enderror"
                                       style="width:100px;" min="1" max="120" placeholder="12">
                                <span class="ml-2 small text-muted">months</span>
                                @error('recallMonths')
                                    <span class="text-danger small ml-2">{{ $message }}</span>
                                @enderror
                            </div>
                            <small class="text-muted d-block mt-1">Runs daily at 9 AM. Each patient is only contacted once per cycle.</small>
                        </div>
                    @endif

                    <div class="d-flex justify-content-end">
                        <button type="button"
                                wire:click="saveRecallSettings"
                                wire:loading.attr="disabled" wire:target="saveRecallSettings"
                                class="btn btn-sm btn-primary font-weight-bold shadow-sm">
                            <span wire:loading.remove wire:target="saveRecallSettings">
                                <i class="fas fa-save mr-1"></i> Save
                            </span>
                            <span wire:loading wire:target="saveRecallSettings">Saving…</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Birthday SMS patient filter --}}
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header border-0 py-3 bg-white d-flex align-items-center">
                    <span class="badge badge-danger p-2 mr-3" style="font-size:15px; border-radius:8px; min-width:36px; text-align:center;">
                        <i class="fas fa-filter"></i>
                    </span>
                    <div>
                        <h6 class="mb-0 font-weight-bold">Birthday Wishes — Patient Filter</h6>
                        <small class="text-muted">Control which patients receive birthday SMS to avoid messaging inactive patients.</small>
                    </div>
                </div>
                <div class="card-body">

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-muted d-block mb-2">Send birthday wishes to:</label>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="bf_all" name="birthdayFilter" value="all"
                                   class="custom-control-input"
                                   wire:model.live="birthdayFilter">
                            <label class="custom-control-label" for="bf_all">
                                <strong>All patients</strong>
                                <span class="text-muted small ml-1">— every patient with a DOB and contact number</span>
                            </label>
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="bf_year" name="birthdayFilter" value="this_year"
                                   class="custom-control-input"
                                   wire:model.live="birthdayFilter">
                            <label class="custom-control-label" for="bf_year">
                                <strong>Active this year</strong>
                                <span class="text-muted small ml-1">— patients with a consultation in the current calendar year</span>
                            </label>
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="bf_24" name="birthdayFilter" value="last_24_months"
                                   class="custom-control-input"
                                   wire:model.live="birthdayFilter">
                            <label class="custom-control-label" for="bf_24">
                                <strong>Active in the last 24 months</strong>
                                <span class="text-muted small ml-1">— patients with a consultation in the past 2 years</span>
                            </label>
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="bf_custom" name="birthdayFilter" value="custom"
                                   class="custom-control-input"
                                   wire:model.live="birthdayFilter">
                            <label class="custom-control-label" for="bf_custom">
                                <strong>Custom range</strong>
                                <span class="text-muted small ml-1">— specify how many months back to check</span>
                            </label>
                        </div>

                        @if($birthdayFilter === 'custom')
                            <div class="mt-2 ml-4 d-flex align-items-center">
                                <input type="number"
                                       wire:model="birthdayCustomMonths"
                                       class="form-control form-control-sm bg-light border-0 @error('birthdayCustomMonths') is-invalid @enderror"
                                       style="width:100px;"
                                       min="1" max="120" placeholder="e.g. 18">
                                <span class="ml-2 small text-muted">months back</span>
                                @error('birthdayCustomMonths')
                                    <span class="text-danger small ml-2">{{ $message }}</span>
                                @enderror
                            </div>
                        @endif

                        @error('birthdayFilter')
                            <span class="text-danger small d-block mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="button"
                                wire:click="saveBirthdaySettings"
                                wire:loading.attr="disabled" wire:target="saveBirthdaySettings"
                                class="btn btn-sm btn-primary font-weight-bold shadow-sm">
                            <span wire:loading.remove wire:target="saveBirthdaySettings">
                                <i class="fas fa-save mr-1"></i> Save Filter
                            </span>
                            <span wire:loading wire:target="saveBirthdaySettings">Saving…</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="alert alert-warning border-0 shadow-sm small mb-0">
                <i class="fas fa-birthday-cake mr-1"></i>
                <strong>Birthday Wishes automation:</strong>
                The command <code>php artisan sms:birthday-wishes</code> is already scheduled to run
                daily at 8 AM via the task scheduler. Ensure your server's cron is running
                <code>php artisan schedule:run</code> every minute.
                Use <code>--dry-run</code> to preview recipients without sending.
            </div>

        </div>
    </div>
</div>

<script>
function insertPlaceholder(key, placeholder) {
    var el = document.getElementById('tpl-' + key);
    if (!el) return;
    var start = el.selectionStart, end = el.selectionEnd;
    el.value = el.value.slice(0, start) + placeholder + el.value.slice(end);
    el.selectionStart = el.selectionEnd = start + placeholder.length;
    el.dispatchEvent(new Event('input'));
    el.focus();
}
</script>
</div>
