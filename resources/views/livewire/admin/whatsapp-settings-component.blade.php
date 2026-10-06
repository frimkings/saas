<div class="clinic-ui ui-page" style="max-width:980px;margin:0 auto">
    <p class="text-slate-500 text-sm uppercase font-semibold mb-1">Communications</p>

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h5 class="mb-0 font-semibold" style="color:#25D366;">
                <i class="fab fa-whatsapp mr-2"></i>WhatsApp Business API
            </h5>
            <p class="text-slate-500 text-sm mb-0">Send appointment reminders, birthday wishes, and recall messages via WhatsApp.</p>
        </div>
        <div class="flex items-center gap-2">
            <input wire:click="toggleWhatsApp" type="checkbox"
                class="rounded border-slate-300 text-teal-700" id="waEnabled"
                {{ $whatsappEnabled ? 'checked' : '' }}>
            <label class="font-semibold" for="waEnabled">
                {{ $whatsappEnabled ? 'Enabled' : 'Disabled' }}
            </label>
        </div>
    </div>

    {{-- Setup guide callout --}}
    <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 mb-6">
        <h6 class="font-semibold"><i class="fas fa-info-circle mr-1"></i> Setup Requirements</h6>
        <ol class="mb-0 pl-4 text-sm">
            <li>Create a <strong>WhatsApp Business Account</strong> and register a phone number in <a href="https://business.facebook.com/wa/manage/phone-numbers/" target="_blank" rel="noopener">Meta Business Manager</a>.</li>
            <li>Generate a <strong>permanent access token</strong> via the Meta Developer portal.</li>
            <li>Create and get <strong>message templates approved</strong> by Meta for each message type below.</li>
            <li>Enter your <strong>Phone Number ID</strong> and <strong>Access Token</strong> below and click Save.</li>
        </ol>
    </div>

    {{-- Credentials --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-success shadow-sm mb-6">
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
            <h6 class="font-semibold mb-0"><i class="fas fa-key mr-1"></i> API Credentials</h6>
        </div>
        <div class="card-body p-4">
            <div class="flex flex-wrap -mx-2">
                <div class="w-full md:w-6/12 px-2 mb-4">
                    <label>Phone Number ID <span class="text-red-700">*</span></label>
                    <input wire:model="phoneNumberId" type="text"
                        class="form-control ui-input @error('phoneNumberId') is-invalid @enderror"
                        placeholder="e.g. 123456789012345">
                    <small class="text-slate-500">Found in Meta Developer → App → WhatsApp → API Setup</small>
                    @error('phoneNumberId')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
                <div class="w-full md:w-6/12 px-2 mb-4">
                    <label>Access Token</label>
                    <input wire:model="accessToken" type="password"
                        class="form-control ui-input"
                        placeholder="Leave blank to keep existing token"
                        autocomplete="new-password">
                    <small class="text-slate-500">Enter only to update. Current value is stored encrypted.</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Template configuration --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-primary shadow-sm mb-6">
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
            <h6 class="font-semibold mb-0"><i class="fas fa-file-alt mr-1"></i> Message Templates</h6>
            <div class="ml-auto flex items-center gap-1">
                <small class="text-slate-500">Templates must be pre-approved in Meta Business Manager</small>
            </div>
        </div>
        <div class="card-body p-4">

            {{-- Appointment reminders --}}
            <div class="border border-slate-200 rounded-md p-4 mb-4 bg-slate-50">
                <h6 class="font-semibold mb-1"><i class="fas fa-calendar-check text-teal-700 mr-1"></i> Appointment Reminders</h6>
                <p class="text-sm text-slate-500 mb-2">
                    Used when a patient's appointment has reminder channel set to <strong>WhatsApp</strong> or <strong>Both</strong>.<br>
                    Template body parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Appointment reason &nbsp;|&nbsp; <code>{{3}}</code> Date &nbsp;|&nbsp; <code>{{4}}</code> Time &nbsp;|&nbsp; <code>{{5}}</code> Clinic name
                </p>
                <div class="flex flex-wrap -mx-2">
                    <div class="w-full md:w-6/12 px-2 mb-0">
                        <label class="text-sm">Template Name</label>
                        <input wire:model="apptTemplate" type="text"
                            class="form-control ui-input ui-input-sm"
                            placeholder="e.g. appointment_reminder">
                    </div>
                    <div class="w-full md:w-3/12 px-2 mb-0">
                        <label class="text-sm">Language Code</label>
                        <input wire:model="apptTemplateLang" type="text"
                            class="form-control ui-input ui-input-sm"
                            placeholder="e.g. en">
                    </div>
                </div>
            </div>

            {{-- Birthday --}}
            <div class="border border-slate-200 rounded-md p-4 mb-4 bg-slate-50">
                <h6 class="font-semibold mb-1"><i class="fas fa-birthday-cake text-amber-600 mr-1"></i> Birthday Wishes</h6>
                <p class="text-sm text-slate-500 mb-2">
                    Parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Clinic name
                </p>
                <div class="w-full md:w-6/12 mb-0 px-0">
                    <label class="text-sm">Template Name</label>
                    <input wire:model="birthdayTemplate" type="text"
                        class="form-control ui-input ui-input-sm"
                        placeholder="e.g. birthday_wishes (leave blank to use SMS only)">
                </div>
            </div>

            {{-- Recall --}}
            <div class="border border-slate-200 rounded-md p-4 mb-4 bg-slate-50">
                <h6 class="font-semibold mb-1"><i class="fas fa-redo text-sky-700 mr-1"></i> Patient Recall</h6>
                <p class="text-sm text-slate-500 mb-2">
                    Parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Clinic name
                </p>
                <div class="w-full md:w-6/12 mb-0 px-0">
                    <label class="text-sm">Template Name</label>
                    <input wire:model="recallTemplate" type="text"
                        class="form-control ui-input ui-input-sm"
                        placeholder="e.g. patient_recall (leave blank to use SMS only)">
                </div>
            </div>

            {{-- Spectacle renewal --}}
            <div class="border border-slate-200 rounded-md p-4 bg-slate-50">
                <h6 class="font-semibold mb-1"><i class="fas fa-glasses text-slate-500 mr-1"></i> Spectacle Renewal</h6>
                <p class="text-sm text-slate-500 mb-2">
                    Parameters (in order): <code>{{1}}</code> Patient name &nbsp;|&nbsp; <code>{{2}}</code> Renewal date &nbsp;|&nbsp; <code>{{3}}</code> Clinic name
                </p>
                <div class="w-full md:w-6/12 mb-0 px-0">
                    <label class="text-sm">Template Name</label>
                    <input wire:model="renewalTemplate" type="text"
                        class="form-control ui-input ui-input-sm"
                        placeholder="e.g. spectacle_renewal (leave blank to use SMS only)">
                </div>
            </div>

        </div>
    </div>

    {{-- Bulk channel preference --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-warning shadow-sm mb-6">
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
            <h6 class="font-semibold mb-0"><i class="fas fa-broadcast-tower mr-1"></i> Bulk Notification Channel</h6>
        </div>
        <div class="card-body p-4">
            <p class="text-sm text-slate-500 mb-2">Controls how birthday wishes, patient recalls, and spectacle renewal reminders are sent.</p>
            <div class="flex" style="gap: 12px; flex-wrap: wrap;">
                <div class="flex items-center gap-2">
                    <input wire:model="bulkChannel" type="radio" value="sms"
                        class="rounded border-slate-300 text-teal-700" id="bulkSms">
                    <label class="" for="bulkSms">SMS only</label>
                </div>
                <div class="flex items-center gap-2">
                    <input wire:model="bulkChannel" type="radio" value="whatsapp"
                        class="rounded border-slate-300 text-teal-700" id="bulkWa">
                    <label class="" for="bulkWa">WhatsApp only</label>
                </div>
                <div class="flex items-center gap-2">
                    <input wire:model="bulkChannel" type="radio" value="both"
                        class="rounded border-slate-300 text-teal-700" id="bulkBoth">
                    <label class="" for="bulkBoth">Both (SMS + WhatsApp)</label>
                </div>
            </div>
        </div>
    </div>

    {{-- Save --}}
    <div class="flex justify-end mb-6">
        <button wire:click="save" class="btn ui-button ui-button-primary">
            <i class="fas fa-save mr-1"></i> Save WhatsApp Settings
        </button>
    </div>

    {{-- Test send --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white card-secondary shadow-sm">
        <div class="card-header border-b border-slate-200 bg-slate-50 px-4 py-2">
            <h6 class="font-semibold mb-0"><i class="fas fa-paper-plane mr-1"></i> Send Test Message</h6>
        </div>
        <div class="card-body p-4">
            <p class="text-sm text-slate-500 mb-4">
                Sends a plain text message. <strong>Note:</strong> Plain text is only deliverable if the recipient has messaged your WhatsApp number in the last 24 hours. For template testing, use the <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Meta Graph API Explorer</a>.
            </p>
            <div class="flex flex-wrap -mx-2 items-end">
                <div class="w-full md:w-5/12 px-2 mb-0">
                    <label>Phone Number</label>
                    <input wire:model="testPhone" type="text"
                        class="form-control ui-input @error('testPhone') is-invalid @enderror"
                        placeholder="e.g. 0244000000">
                    @error('testPhone')<div class="ui-error">{{ $message }}</div>@enderror
                </div>
                <div class="w-full md:w-3/12 px-2">
                    <button wire:click="sendTest" class="btn ui-button ui-button-secondary">
                        <i class="fab fa-whatsapp mr-1"></i> Send Test
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
