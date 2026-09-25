<section class="quick-followup" x-data="{
    scheduled: $wire.entangle('appointmentScheduledAt'),
    reminder: $wire.entangle('appointmentReminderChannel'),
    diagnoses: $wire.entangle('selectedDiagnoses'),
    state: $wire.entangle('state'),
    date: '', time: '09:00', whatsapp: false, sms: false,
    init() {
        if (this.scheduled) { const parts=String(this.scheduled).split('T'); this.date=parts[0]||''; this.time=(parts[1]||'09:00').slice(0,5); }
        this.whatsapp=['whatsapp','both'].includes(this.reminder); this.sms=['sms','both'].includes(this.reminder);
    },
    localDate(value) { const d=new Date(value); return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-'); },
    preset(kind) { const d=new Date(); if(kind==='week')d.setDate(d.getDate()+7); if(kind==='month')d.setMonth(d.getMonth()+1); if(kind==='six')d.setMonth(d.getMonth()+6); this.date=this.localDate(d); this.syncDate(); },
    syncDate() { this.scheduled=this.date ? this.date+'T'+(this.time||'09:00') : ''; },
    syncReminder() { this.reminder=this.whatsapp&&this.sms?'both':(this.whatsapp?'whatsapp':(this.sms?'sms':'none')); },
    get ready() { return String(this.state?.chiefComplaint||'').trim() && Array.isArray(this.diagnoses)&&this.diagnoses.length>0; }
}">
    <div class="quick-followup__head">
        <h6><i class="far fa-calendar-alt"></i> Follow-up Appointment</h6>
        <span>Optional</span>
    </div>

    <div class="quick-followup__presets" aria-label="Quick follow-up intervals">
        <button type="button" @click="preset('week')">In 1 Week</button>
        <button type="button" @click="preset('month')">In 1 Month</button>
        <button type="button" @click="preset('six')">In 6 Months</button>
    </div>

    <div class="quick-followup__grid">
        <div class="quick-followup__field quick-followup__field--reason">
            <label>Reason</label>
            <select wire:model="appointmentTitle" class="form-control form-control-sm @error('appointmentTitle') is-invalid @enderror">
                <option value="">Select a reason</option>
                @foreach($this->appointmentReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
            </select>
            @error('appointmentTitle')<small class="text-danger">{{ $message }}</small>@enderror
        </div>
        <div class="quick-followup__field">
            <label>Date</label>
            <input type="date" x-model="date" @change="syncDate()" min="{{ now()->format('Y-m-d') }}" class="form-control form-control-sm">
        </div>
        <div class="quick-followup__field">
            <label>Time</label>
            <input type="time" x-model="time" @change="syncDate()" class="form-control form-control-sm">
        </div>
        <div class="quick-followup__field">
            <label>Clinician</label>
            <div class="quick-followup__doctor"><i class="fas fa-user-md"></i> {{ auth()->user()->name }}</div>
        </div>
    </div>
    @error('appointmentScheduledAt')<small class="quick-followup__error"><i class="fas fa-exclamation-circle"></i> {{ $message }}</small>@enderror

    <div class="quick-followup__footer">
        <div class="quick-followup__reminders">
            <span>Send reminder via</span>
            <label class="{{ !$whatsAppReminderAvailable ? 'is-disabled' : '' }}"><input type="checkbox" x-model="whatsapp" @change="syncReminder()" {{ !$whatsAppReminderAvailable ? 'disabled' : '' }}> WhatsApp</label>
            <label class="{{ !$smsReminderAvailable ? 'is-disabled' : '' }}"><input type="checkbox" x-model="sms" @change="syncReminder()" {{ !$smsReminderAvailable ? 'disabled' : '' }}> SMS</label>
            <small>{{ $patient->contact ?: 'No phone number' }}</small>
        </div>
        <button type="button" wire:click="{{ $isEditingAppointment ? 'updateAppointment' : 'bookAppointmentFromConsultation' }}" wire:loading.attr="disabled" class="quick-followup__book">
            <span wire:loading.remove wire:target="bookAppointmentFromConsultation,updateAppointment"><i class="fas fa-calendar-check"></i> {{ $isEditingAppointment ? 'Update Follow-up' : 'Book Follow-up' }}</span>
            <span wire:loading wire:target="bookAppointmentFromConsultation,updateAppointment"><i class="fas fa-spinner fa-spin"></i> Saving</span>
        </button>
    </div>

    <div class="quick-followup-summary" :class="ready?'is-ready':'needs-attention'">
        <div><strong>Consultation Summary</strong><span><b x-text="diagnoses.length"></b> diagnoses <i>•</i> <b x-text="Array.isArray(state.odq)?state.odq.length:0"></b> symptoms <i>•</i> <span x-text="String(state.notes||'').trim()?'Notes added':'No notes'"></span> <i>•</i> <span x-text="date?'Follow-up '+date:'No follow-up'"></span></span></div>
        <em x-text="ready?'Ready to Save':'Required Items Missing'"></em>
    </div>
</section>
