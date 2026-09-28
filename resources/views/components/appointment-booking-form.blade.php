{{-- 
================================================================================
APPOINTMENT BOOKING FORM WITH EDIT MODE - COMPLETE VERSION
================================================================================
Features:
- Create new appointments
- Edit existing appointments
- Delete appointments
- Show appointment list
- Inline messages
- Patient locked in doctor context
================================================================================
--}}

@php
    $patientLocked = $patientLocked ?? false;
    $showQuickBook = $showQuickBook ?? false;
@endphp

<div class="card border shadow-sm">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">
            <i class="fas fa-calendar-plus"></i> 
            {{ $isEditingAppointment ? 'Edit Appointment' : 'Schedule Follow-up Appointment' }}
        </h6>
    </div>
    
    <div class="card-body">
        {{-- ⭐ INLINE SUCCESS MESSAGE ⭐ --}}
        @if (session()->has('appointment_success'))
            <div class="border-left border-success bg-light p-3 mb-3 appointment-success-msg" id="appointmentSuccessMsg">
                <div class="d-flex align-items-start">
                    <i class="fas fa-check-circle text-success mr-2 mt-1" style="font-size: 1.5rem;"></i>
                    <div class="flex-grow-1">
                        <strong class="text-success d-block">Success!</strong>
                        <p class="mb-0 text-dark">{{ session('appointment_success') }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- ⭐ INLINE ERROR MESSAGE ⭐ --}}
        @if (session()->has('appointment_error'))
            <div class="border-left border-danger bg-light p-3 mb-3">
                <div class="d-flex align-items-start">
                    <i class="fas fa-exclamation-triangle text-danger mr-2 mt-1" style="font-size: 1.5rem;"></i>
                    <div class="flex-grow-1">
                        <strong class="text-danger d-block">Error!</strong>
                        <p class="mb-0 text-dark">{{ session('appointment_error') }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- ⭐ INLINE INFO MESSAGE ⭐ --}}
        @if (session()->has('appointment_info'))
            <div class="border-left border-info bg-light p-3 mb-3">
                <div class="d-flex align-items-start">
                    <i class="fas fa-info-circle text-info mr-2 mt-1" style="font-size: 1.5rem;"></i>
                    <div class="flex-grow-1">
                        <strong class="text-info d-block">Info</strong>
                        <p class="mb-0 text-dark">{{ session('appointment_info') }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- ⭐ VALIDATION ERRORS ⭐ --}}
        @if ($errors->any())
            <div class="border-left border-warning bg-light p-3 mb-3">
                <div class="d-flex align-items-start">
                    <i class="fas fa-exclamation-circle text-warning mr-2 mt-1"></i>
                    <div class="flex-grow-1">
                        <strong class="text-warning d-block mb-1">Please fix the following:</strong>
                        <ul class="mb-0 pl-3">
                            @foreach ($errors->all() as $error)
                                <li class="text-dark">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        {{-- ⭐ APPOINTMENT FORM ⭐ --}}
        <div>
            
            {{-- Patient Section (Locked in doctor context) --}}
            @if($patientLocked)
                <div class="mb-3 p-2 bg-light rounded border">
                    <small class="text-muted d-block mb-1">
                        <i class="fas fa-user"></i> Patient
                    </small>
                    <strong class="text-dark">
                        <i class="fas fa-check-circle text-success mr-1"></i>
                        {{ $appointmentSelectedPatientName ?? 'Not set' }}
                    </strong>
                </div>
            @else
                {{-- Patient Search (for secretary context) --}}
                <div class="form-group position-relative">
                    <label class="font-weight-bold">
                        Patient <span class="text-danger">*</span>
                    </label>
                    
                    @if($appointmentSelectedPatientName)
                        <div class="alert alert-info d-flex justify-content-between align-items-center mb-0 p-2">
                            <span class="font-weight-bold">
                                <i class="fas fa-user"></i> {{ $appointmentSelectedPatientName }}
                            </span>
                            <button type="button" wire:click="resetAppointmentForm" 
                                    class="btn btn-link btn-sm text-danger p-0">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    @else
                        <input type="text" 
                               wire:model.live.debounce.300ms="appointmentPatientSearch"
                               class="form-control @error('appointmentPatientId') is-invalid @enderror" 
                               placeholder="Search patient name or ID...">
                        
                        @if(!empty($appointmentSearchablePatients) && count($appointmentSearchablePatients) > 0)
                            <div class="list-group position-absolute w-100 shadow-lg mt-1" style="z-index: 1100;">
                                @foreach($appointmentSearchablePatients as $p)
                                    <button type="button" 
                                            wire:click="selectAppointmentPatient({{ $p->id }}, '{{ addslashes($p->name) }}')" 
                                            class="list-group-item list-group-item-action small">
                                        <i class="fas fa-user-circle text-primary"></i> {{ $p->name }}
                                        <small class="text-muted d-block">ID: {{ $p->pxnumber }}</small>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    @endif
                    @error('appointmentPatientId') 
                        <small class="text-danger d-block mt-1">{{ $message }}</small> 
                    @enderror
                </div>
            @endif

            {{-- Reason for Appointment --}}
            <div class="form-group">
                <label class="font-weight-bold">
                    Reason for Appointment <span class="text-danger">*</span>
                </label>
                <select wire:model="appointmentTitle"
                        class="form-control @error('appointmentTitle') is-invalid @enderror">
                    <option value="">-- Select a reason --</option>
                    @foreach($this->appointmentReasons as $reason)
                        <option value="{{ $reason }}">{{ $reason }}</option>
                    @endforeach
                </select>
                @error('appointmentTitle')
                    <small class="text-danger d-block mt-1">{{ $message }}</small>
                @enderror
            </div>

            <div class="form-group">
                <label class="font-weight-bold">Follow-up Recall Category</label>
                <select wire:model="appointmentRecallCategory"
                        class="form-control @error('appointmentRecallCategory') is-invalid @enderror">
                    <option value="">Use appointment reason</option>
                    @foreach($this->appointmentRecallCategories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>
                @error('appointmentRecallCategory')
                    <small class="text-danger d-block mt-1">{{ $message }}</small>
                @enderror
            </div>

            {{-- Date/Time --}}
            <div class="form-group">
                <label class="font-weight-bold">
                    Date/Time <span class="text-danger">*</span>
                </label>
                <input type="datetime-local" 
                       wire:model="appointmentScheduledAt"
                       class="form-control @error('appointmentScheduledAt') is-invalid @enderror"
                       min="{{ now()->format('Y-m-d\TH:i') }}">
                @error('appointmentScheduledAt') 
                    <small class="text-danger d-block mt-1">{{ $message }}</small> 
                @enderror
            </div>

            <div class="form-group">
                <label class="font-weight-bold">Reminder Channel</label>
                <select wire:model="appointmentReminderChannel"
                        class="form-control @error('appointmentReminderChannel') is-invalid @enderror">
                    <option value="whatsapp">WhatsApp</option>
                    <option value="sms">SMS</option>
                    <option value="both">SMS + WhatsApp</option>
                    <option value="none">No reminder</option>
                </select>
                @error('appointmentReminderChannel')
                    <small class="text-danger d-block mt-1">{{ $message }}</small>
                @enderror
            </div>

            {{-- Notes (Optional) --}}
            <div class="form-group mb-4">
                <label class="font-weight-bold">Notes (Optional)</label>
                <textarea wire:model="appointmentNotes"
                          class="form-control" 
                          rows="2" 
                          placeholder="Additional notes or instructions..."></textarea>
            </div>

            {{-- Action Buttons --}}
            <div class="d-flex justify-content-between">
                <button type="button" 
                        wire:click="{{ $isEditingAppointment ? 'cancelAppointmentEdit' : 'resetAppointmentForm' }}" 
                        class="btn btn-outline-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
                
                {{-- ⭐ SUBMIT BUTTON - Changes based on mode ⭐ --}}
                <button type="button"
                        wire:click="{{ $isEditingAppointment ? 'updateAppointment' : 'bookAppointmentFromConsultation' }}"
                        class="btn {{ $isEditingAppointment ? 'btn-success' : 'btn-primary' }} font-weight-bold px-4"
                        wire:loading.attr="disabled"
                        wire:target="{{ $isEditingAppointment ? 'updateAppointment' : 'bookAppointmentFromConsultation' }}">
                    <span wire:loading.remove wire:target="{{ $isEditingAppointment ? 'updateAppointment' : 'bookAppointmentFromConsultation' }}">
                        <i class="fas {{ $isEditingAppointment ? 'fa-save' : 'fa-calendar-check' }}"></i> 
                        {{ $isEditingAppointment ? 'Update Appointment' : 'Book Appointment' }}
                    </span>
                    <span wire:loading wire:target="{{ $isEditingAppointment ? 'updateAppointment' : 'bookAppointmentFromConsultation' }}">
                        <i class="fas fa-spinner fa-spin"></i> 
                        {{ $isEditingAppointment ? 'Updating...' : 'Booking...' }}
                    </span>
                </button>
            </div>
        </div>

        {{-- ⭐ APPOINTMENT LIST (Show upcoming appointments for this patient) ⭐ --}}
        @if($patientLocked && !$isEditingAppointment && isset($this->patientAppointments) && $this->patientAppointments->count() > 0)
            <div class="border-top mt-4 pt-3">
                <h6 class="font-weight-bold mb-3">
                    <i class="fas fa-calendar-alt text-primary"></i> Upcoming Appointments
                </h6>
                <div class="list-group">
                    @foreach($this->patientAppointments as $appt)
                        <div class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <strong class="text-dark">{{ $appt->title }}</strong>
                                    <span class="badge badge-{{ $appt->status === 'Pending' ? 'warning' : 'info' }} ml-2">
                                        {{ $appt->status }}
                                    </span>
                                </div>
                                <small class="text-muted d-block">
                                    <i class="fas fa-calendar"></i> 
                                    {{ \Carbon\Carbon::parse($appt->scheduled_at)->format('M d, Y \a\t h:i A') }}
                                </small>
                                @if($appt->recall_category || $appt->reminder_channel)
                                    <small class="text-muted d-block mt-1">
                                        <i class="fas fa-tag"></i> {{ $appt->recall_category ?? 'Routine Review' }}
                                        <span class="mx-1">|</span>
                                        <i class="fas fa-bell"></i> {{ ucfirst($appt->reminder_channel ?? 'whatsapp') }}
                                    </small>
                                @endif
                                @if($appt->notes)
                                    <small class="text-muted d-block mt-1">
                                        <i class="fas fa-sticky-note"></i> {{ Str::limit($appt->notes, 50) }}
                                    </small>
                                @endif
                            </div>
                            <div class="btn-group-vertical btn-group-sm ml-2">
                                <button wire:click="editAppointment({{ $appt->id }})" 
                                        class="btn btn-outline-primary btn-sm"
                                        title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button wire:click="deleteAppointment({{ $appt->id }})" 
                                        wire:confirm="Delete this appointment?"
                                        class="btn btn-outline-danger btn-sm"
                                        title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ⭐ AUTO-FADE SUCCESS MESSAGE ⭐ --}}
@if (session()->has('appointment_success'))
    <script>
        setTimeout(function() {
            var successMsg = document.getElementById('appointmentSuccessMsg');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s ease';
                successMsg.style.opacity = '0';
                setTimeout(function() {
                    successMsg.remove();
                }, 500);
            }
        }, 5000);
    </script>
@endif

<style>
    .border-left {
        border-left-width: 4px !important;
    }
    
    .appointment-success-msg {
        border-radius: 0.25rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .fa-spinner.fa-spin {
        animation: spin 1s linear infinite;
    }

    .list-group-item {
        transition: background-color 0.2s;
    }

    .list-group-item:hover {
        background-color: #f8f9fa;
    }
</style>
