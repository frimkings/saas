<div class="p-4">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h5 class="mb-0 font-weight-bold" style="color:#25D366;">
                <i class="fab fa-whatsapp mr-2"></i>WhatsApp Business API
            </h5>
            <p class="text-muted small mb-0">Send appointment reminders, birthday wishes, and recall messages via WhatsApp.</p>
        </div>
        <div class="custom-control custom-switch">
            <input wire:click="toggleWhatsApp" type="checkbox"
                class="custom-control-input" id="waEnabled"
                {{ $whatsappEnabled ? 'checked' : '' }}>
            <label class="custom-control-label font-weight-bold" for="waEnabled">
                {{ $whatsappEnabled ? 'Enabled' : 'Disabled' }}
            </label>
        </div>
    </div>

    {{-- Setup guide callout --}}
    <div class="alert alert-info alert-dismissible mb-4">
        <h6 class="font-weight-bold"><i class="fas fa-info-circle mr-1"></i> Setup Requirements</h6>
        <ol class="mb-0 pl-3 small">
            <li>Create a <strong>WhatsApp Business Account</strong> and register a phone number in <a href="https://business.facebook.com/wa/manage/phone-numbers/" target="_blank" rel="noopener">Meta Business Manager</a>.</li>
            <li>Generate a <strong>permanent access token</strong> via the Meta Developer portal.</li>
            <li>Create and get <strong>message templates approved</strong> by Meta for each message type below.</li>
            <li>Enter your <strong>Phone Number ID</strong> and <strong>Access Token</strong> below and click Save.</li>
        </ol>
    </div>

    {{-- Credentials --}}
    <div class="card card-outline card-success shadow-sm mb-4">
        <div class="card-header">
            <h6 class="card-title mb-0"><i class="fas fa-key mr-1"></i> API Credentials</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Phone Number ID <span class="text-danger">*</span></label>
                    <input wire:model="phoneNumberId" type="text"
                        class="form-control @error('phoneNumberId') is-invalid @enderror"
                        placeholder="e.g. 123456789012345">
                    <small class="text-muted">Found in Meta Developer → App → WhatsApp → API Setup</small>
                    @error('phoneNumberId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6 form-group">
                    <label>Access Token</label>
                    <input wire:model="accessToken" type="password"
                        class="form-control"
                        placeholder="Leave blank to keep existing token"
                        autocomplete="new-password">
                    <small class="text-muted">Enter only to update. Current value is stored encrypted.</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Template configuration --}}
    <div class="card card-outline card-primary shadow-sm mb-4">
        <div class="card-header">
            <h6 class="card-title mb-0"><i class="fas fa-file-alt mr-1"></i> Message Templates</h6>
            <div class="card-tools">
                <small class="text-muted">Templates must be pre-approved in Meta Business Manager</small>
            </div>
        </div>
        <div class="card-body">

            {{-- Appointment reminders --}}
            <div class="border rounded p-3 mb-3 bg-light">
                <h6 class="font-weight-bold mb-1"><i class="fas fa-calendar-check text-primary mr-1"></i> Appointment Reminders</h6>
                <p class="small text-muted mb-2">
                    Used when a patient's appointment has reminder channel set to <strong>WhatsApp</strong> or <strong>Both</strong>.<br>
                    Template body parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Appointment reason &nbsp;|&nbsp; <code>{{3}}</code> Date &nbsp;|&nbsp; <code>{{4}}</code> Time &nbsp;|&nbsp; <code>{{5}}</code> Clinic name
                </p>
                <div class="row">
                    <div class="col-md-6 form-group mb-0">
                        <label class="small">Template Name</label>
                        <input wire:model="apptTemplate" type="text"
                            class="form-control form-control-sm"
                            placeholder="e.g. appointment_reminder">
                    </div>
                    <div class="col-md-3 form-group mb-0">
                        <label class="small">Language Code</label>
                        <input wire:model="apptTemplateLang" type="text"
                            class="form-control form-control-sm"
                            placeholder="e.g. en">
                    </div>
                </div>
            </div>

            {{-- Birthday --}}
            <div class="border rounded p-3 mb-3 bg-light">
                <h6 class="font-weight-bold mb-1"><i class="fas fa-birthday-cake text-warning mr-1"></i> Birthday Wishes</h6>
                <p class="small text-muted mb-2">
                    Parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Clinic name
                </p>
                <div class="col-md-6 form-group mb-0 px-0">
                    <label class="small">Template Name</label>
                    <input wire:model="birthdayTemplate" type="text"
                        class="form-control form-control-sm"
                        placeholder="e.g. birthday_wishes (leave blank to use SMS only)">
                </div>
            </div>

            {{-- Recall --}}
            <div class="border rounded p-3 mb-3 bg-light">
                <h6 class="font-weight-bold mb-1"><i class="fas fa-redo text-info mr-1"></i> Patient Recall</h6>
                <p class="small text-muted mb-2">
                    Parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Clinic name
                </p>
                <div class="col-md-6 form-group mb-0 px-0">
                    <label class="small">Template Name</label>
                    <input wire:model="recallTemplate" type="text"
                        class="form-control form-control-sm"
                        placeholder="e.g. patient_recall (leave blank to use SMS only)">
                </div>
            </div>

            {{-- Spectacle renewal --}}
            <div class="border rounded p-3 bg-light">
                <h6 class="font-weight-bold mb-1"><i class="fas fa-glasses text-secondary mr-1"></i> Spectacle Renewal</h6>
                <p class="small text-muted mb-2">
                    Parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Renewal date &nbsp;|&nbsp; <code>{{3}}</code> Clinic name
                </p>
                <div class="col-md-6 form-group mb-0 px-0">
                    <label class="small">Template Name</label>
                    <input wire:model="renewalTemplate" type="text"
                        class="form-control form-control-sm"
                        placeholder="e.g. spectacle_renewal (leave blank to use SMS only)">
                </div>
            </div>

        </div>
    </div>

    {{-- Bulk channel preference --}}
    <div class="card card-outline card-warning shadow-sm mb-4">
        <div class="card-header">
            <h6 class="card-title mb-0"><i class="fas fa-broadcast-tower mr-1"></i> Bulk Notification Channel</h6>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-2">Controls how birthday wishes, patient recalls, and spectacle renewal reminders are sent.</p>
            <div class="d-flex" style="gap: 12px; flex-wrap: wrap;">
                <div class="custom-control custom-radio">
                    <input wire:model="bulkChannel" type="radio" value="sms"
                        class="custom-control-input" id="bulkSms">
                    <label class="custom-control-label" for="bulkSms">SMS only</label>
                </div>
                <div class="custom-control custom-radio">
                    <input wire:model="bulkChannel" type="radio" value="whatsapp"
                        class="custom-control-input" id="bulkWa">
                    <label class="custom-control-label" for="bulkWa">WhatsApp only</label>
                </div>
                <div class="custom-control custom-radio">
                    <input wire:model="bulkChannel" type="radio" value="both"
                        class="custom-control-input" id="bulkBoth">
                    <label class="custom-control-label" for="bulkBoth">Both (SMS + WhatsApp)</label>
                </div>
            </div>
        </div>
    </div>

    {{-- Save --}}
    <div class="d-flex justify-content-end mb-4">
        <button wire:click="save" class="btn btn-success">
            <i class="fas fa-save mr-1"></i> Save WhatsApp Settings
        </button>
    </div>

    {{-- Test send --}}
    <div class="card card-outline card-secondary shadow-sm">
        <div class="card-header">
            <h6 class="card-title mb-0"><i class="fas fa-paper-plane mr-1"></i> Send Test Message</h6>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Sends a plain text message. <strong>Note:</strong> Plain text is only deliverable if the recipient has messaged your WhatsApp number in the last 24 hours. For template testing, use the <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Meta Graph API Explorer</a>.
            </p>
            <div class="row align-items-end">
                <div class="col-md-5 form-group mb-0">
                    <label>Phone Number</label>
                    <input wire:model="testPhone" type="text"
                        class="form-control @error('testPhone') is-invalid @enderror"
                        placeholder="e.g. 0244000000">
                    @error('testPhone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <button wire:click="sendTest" class="btn btn-outline-success">
                        <i class="fab fa-whatsapp mr-1"></i> Send Test
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
