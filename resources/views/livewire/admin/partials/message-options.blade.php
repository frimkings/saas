{{-- A message's own settings, shown in its editor on Communications → Messages. --}}
@php
    $optionRow = 'd-flex flex-wrap align-items-center small mt-3 pt-3 border-top';
    $numberInput = 'form-control form-control-sm bg-white mx-1';
@endphp

@switch($key)
    @case('aftercare_followup')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-muted font-weight-bold">Send</span>
            <input type="number" min="1" max="60" wire:model="aftercareDays" class="{{ $numberInput }}" style="width:70px" aria-label="Days after collection">
            <span class="text-muted">days after the glasses are collected.</span>
            <button type="button" wire:click="saveFollowUpTiming" class="btn btn-sm btn-outline-primary ml-2">Save timing</button>
        </div>
        @error('aftercareDays') <span class="text-danger small d-block">{{ $message }}</span> @enderror
        @break

    @case('clinical_recall')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-muted font-weight-bold">Send</span>
            <input type="number" min="0" max="60" wire:model="recallLeadDays" class="{{ $numberInput }}" style="width:70px" aria-label="Days before the exam is due">
            <span class="text-muted">days before the exam is due. The doctor sets the date on the patient's consultation page.</span>
            <button type="button" wire:click="saveFollowUpTiming" class="btn btn-sm btn-outline-primary ml-2">Save timing</button>
        </div>
        @error('recallLeadDays') <span class="text-danger small d-block">{{ $message }}</span> @enderror
        @break

    @case('balance_reminder')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-muted font-weight-bold">First reminder</span>
            <input type="number" min="0" max="90" wire:model="balanceFirstDays" class="{{ $numberInput }}" style="width:65px" aria-label="Days before the first reminder">
            <span class="text-muted">days after the bill, then every</span>
            <input type="number" min="1" max="90" wire:model="balanceEveryDays" class="{{ $numberInput }}" style="width:65px" aria-label="Days between reminders">
            <span class="text-muted">days, at most</span>
            <input type="number" min="1" max="10" wire:model="balanceMax" class="{{ $numberInput }}" style="width:65px" aria-label="Most reminders per bill">
            <span class="text-muted">times per bill. One text per person with the total.</span>
            <button type="button" wire:click="saveBalanceReminderSettings" class="btn btn-sm btn-outline-primary ml-2">Save schedule</button>
        </div>
        @foreach(['balanceFirstDays', 'balanceEveryDays', 'balanceMax'] as $field)
            @error($field) <span class="text-danger small d-block">{{ $message }}</span> @enderror
        @endforeach
        @break

    @case('feedback_request')
        <div class="mt-3 pt-3 border-top">
            <label class="small font-weight-bold text-muted mb-1" for="review-link">Review link <span class="font-weight-normal">— fills [REVIEW_LINK], e.g. your Google review page</span></label>
            <div class="d-flex flex-wrap" style="gap:.4rem">
                <input type="url" id="review-link" wire:model="reviewLink" placeholder="https://g.page/r/..." maxlength="500"
                       class="form-control form-control-sm bg-white @error('reviewLink') is-invalid @enderror" style="max-width:420px">
                <button type="button" wire:click="saveReviewLink" class="btn btn-sm btn-outline-primary">Save link</button>
            </div>
            @error('reviewLink') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
            @if(trim($reviewLink) === '' && str_contains($templates[$key]['message'] ?? '', '[REVIEW_LINK]'))
                <small class="text-warning d-block mt-1"><i class="fas fa-exclamation-triangle"></i> Not sent until a review link is saved.</small>
            @endif
        </div>
        @break

    @case('patient_recall')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-muted font-weight-bold">Send when a patient has not visited for</span>
            <input type="number" min="1" max="120" wire:model="recallMonths" class="{{ $numberInput }}" style="width:70px" aria-label="Months without a visit">
            <span class="text-muted">months. Each patient is contacted once per cycle.</span>
            <button type="button" wire:click="saveRecallSettings" class="btn btn-sm btn-outline-primary ml-2">Save</button>
        </div>
        @error('recallMonths') <span class="text-danger small d-block">{{ $message }}</span> @enderror
        @break

    @case('spectacle_renewal')
        <div class="{{ $optionRow }}" style="gap:.25rem">
            <span class="text-muted font-weight-bold">Send</span>
            <input type="number" min="1" max="90" wire:model="renewalReminderDays" class="{{ $numberInput }}" style="width:70px" aria-label="Days before the renewal date">
            <span class="text-muted">days before the renewal date (reminders wait for Super Admin approval).</span>
            <button type="button" wire:click="saveRenewalSettings" class="btn btn-sm btn-outline-primary ml-2">Save timing</button>
        </div>
        @error('renewalReminderDays') <span class="text-danger small d-block">{{ $message }}</span> @enderror
        @break

    @case('birthday_wishes')
        <div class="{{ $optionRow }}" style="gap:.5rem">
            <span class="text-muted font-weight-bold">Send to</span>
            <select wire:model.live="birthdayFilter" class="form-control form-control-sm bg-white" style="width:auto" aria-label="Which patients get birthday wishes">
                <option value="all">All patients</option>
                <option value="this_year">Patients seen this year</option>
                <option value="last_24_months">Patients seen in the last 24 months</option>
                <option value="custom">Patients seen in the last…</option>
            </select>
            @if($birthdayFilter === 'custom')
                <input type="number" min="1" max="120" wire:model="birthdayCustomMonths" class="{{ $numberInput }}" style="width:70px" aria-label="Months">
                <span class="text-muted">months</span>
            @endif
            <button type="button" wire:click="saveBirthdaySettings" class="btn btn-sm btn-outline-primary">Save</button>
        </div>
        @error('birthdayCustomMonths') <span class="text-danger small d-block">{{ $message }}</span> @enderror
        @break

    @case('spectacles_reminder')
        <p class="small text-muted mt-3 pt-3 border-top mb-0"><i class="fas fa-info-circle mr-1"></i>Which days after "Ready" the pickup reminders go is set in Optical Settings.</p>
        @break
@endswitch
