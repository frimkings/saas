{{-- A message's own settings, shown in its editor on Communications → Messages. --}}
@php
    $optionRow = 'mt-4 flex flex-wrap items-center gap-y-2 border-t border-slate-200 pt-4 text-sm';
    $numberInput = 'ui-input ui-input-sm mx-1 !inline-block bg-white';
@endphp

@switch($key)
    @case('aftercare_followup')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-slate-500 font-semibold">Send</span>
            <input type="number" min="1" max="60" wire:model="aftercareDays" class="{{ $numberInput }}" style="width:70px" aria-label="Days after collection">
            <span class="text-slate-500">days after the glasses are collected.</span>
            <button type="button" wire:click="saveFollowUpTiming" class="btn ui-button ui-button-sm ui-button-secondary ml-2">Save timing</button>
        </div>
        @error('aftercareDays') <span class="text-red-700 text-sm block">{{ $message }}</span> @enderror
        @break

    @case('clinical_recall')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-slate-500 font-semibold">Send</span>
            <input type="number" min="0" max="60" wire:model="recallLeadDays" class="{{ $numberInput }}" style="width:70px" aria-label="Days before the exam is due">
            <span class="text-slate-500">days before the exam is due. The doctor sets the date on the patient's consultation page.</span>
            <button type="button" wire:click="saveFollowUpTiming" class="btn ui-button ui-button-sm ui-button-secondary ml-2">Save timing</button>
        </div>
        @error('recallLeadDays') <span class="text-red-700 text-sm block">{{ $message }}</span> @enderror
        @break

    @case('balance_reminder')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-slate-500 font-semibold">First reminder</span>
            <input type="number" min="0" max="90" wire:model="balanceFirstDays" class="{{ $numberInput }}" style="width:65px" aria-label="Days before the first reminder">
            <span class="text-slate-500">days after the bill, then every</span>
            <input type="number" min="1" max="90" wire:model="balanceEveryDays" class="{{ $numberInput }}" style="width:65px" aria-label="Days between reminders">
            <span class="text-slate-500">days, at most</span>
            <input type="number" min="1" max="10" wire:model="balanceMax" class="{{ $numberInput }}" style="width:65px" aria-label="Most reminders per bill">
            <span class="text-slate-500">times per bill. One text per person with the total.</span>
            <button type="button" wire:click="saveBalanceReminderSettings" class="btn ui-button ui-button-sm ui-button-secondary ml-2">Save schedule</button>
        </div>
        @foreach(['balanceFirstDays', 'balanceEveryDays', 'balanceMax'] as $field)
            @error($field) <span class="text-red-700 text-sm block">{{ $message }}</span> @enderror
        @endforeach
        @break

    @case('feedback_request')
        <div class="mt-4 pt-4 border-t border-slate-200 text-sm text-slate-500">
            <i class="fas fa-link mr-1"></i> The review link ([REVIEW_LINK]) is set with the clinic's other links in
            <a href="{{ route('admin.settings', ['tab' => 'links']) }}">Settings &rarr; Clinic Links</a>.
        </div>
        @break

    @case('patient_recall')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-slate-500 font-semibold">Send when a patient has not visited for</span>
            <input type="number" min="1" max="120" wire:model="recallMonths" class="{{ $numberInput }}" style="width:70px" aria-label="Months without a visit">
            <span class="text-slate-500">months. Each patient is contacted once per cycle.</span>
            <button type="button" wire:click="saveRecallSettings" class="btn ui-button ui-button-sm ui-button-secondary ml-2">Save</button>
        </div>
        @error('recallMonths') <span class="text-red-700 text-sm block">{{ $message }}</span> @enderror
        @break

    @case('spectacle_renewal')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-slate-500 font-semibold">Send</span>
            <input type="number" min="1" max="90" wire:model="renewalReminderDays" class="{{ $numberInput }}" style="width:70px" aria-label="Days before the renewal date">
            <span class="text-slate-500">days before the renewal date (reminders wait for Super Admin approval).</span>
            <button type="button" wire:click="saveRenewalSettings" class="btn ui-button ui-button-sm ui-button-secondary ml-2">Save timing</button>
        </div>
        @error('renewalReminderDays') <span class="text-red-700 text-sm block">{{ $message }}</span> @enderror
        @break

    @case('birthday_wishes')
        <div class="{{ $optionRow }}" style="gap:.5rem">
            <span class="text-slate-500 font-semibold">Send to</span>
            <select wire:model.live="birthdayFilter" class="form-control ui-input ui-input-sm bg-white" style="width:auto" aria-label="Which patients get birthday wishes">
                <option value="all">All patients</option>
                <option value="this_year">Patients seen this year</option>
                <option value="last_24_months">Patients seen in the last 24 months</option>
                <option value="custom">Patients seen in the last…</option>
            </select>
            @if($birthdayFilter === 'custom')
                <input type="number" min="1" max="120" wire:model="birthdayCustomMonths" class="{{ $numberInput }}" style="width:70px" aria-label="Months">
                <span class="text-slate-500">months</span>
            @endif
            <button type="button" wire:click="saveBirthdaySettings" class="btn ui-button ui-button-sm ui-button-secondary">Save</button>
        </div>
        @error('birthdayCustomMonths') <span class="text-red-700 text-sm block">{{ $message }}</span> @enderror
        @break

    @case('spectacles_reminder')
        <p class="text-sm text-slate-500 mt-4 pt-4 border-t border-slate-200 mb-0"><i class="fas fa-info-circle mr-1"></i>Which days after "Ready" the pickup reminders go is set in Optical Settings.</p>
        @break
@endswitch
