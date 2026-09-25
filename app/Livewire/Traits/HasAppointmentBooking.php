<?php

namespace App\Livewire\Traits;

use App\Models\Appointments;
use App\Models\Patient;
use App\Services\Messaging\AppointmentNotifier;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Validation\Rule;



trait HasAppointmentBooking
{
    // Appointment properties
    public $appointmentTitle;
    public $appointmentRecallCategory;
    public $appointmentScheduledAt;
    public $appointmentNotes;
    public $appointmentReminderChannel = 'whatsapp';
    public $appointmentPatientId;
    public $appointmentPatientSearch = '';
    public $appointmentSelectedPatientName = '';
    public $appointmentSearchablePatients = [];
    
    // Predefined appointment reasons
    public $appointmentReasons = [
        'Comprehensive Eye Exam',
        'Contact Lens Fitting',
        'Follow-up Visit',
        'Glaucoma Review',
        'Diabetic Review',
        'Post-op Review',
        'Glasses Pickup',
        'Glaucoma Evaluation',
        'Diabetic Eye Exam',
        'To submit Report',
        'Fundus Dilation',
        'Cyclorefraction',
        'Refraction Update',
        'Other',
    ];

    public $appointmentRecallCategories = [
        'Routine Review',
        'Glaucoma Review',
        'Diabetic Review',
        'Post-op Review',
        'Glasses Pickup',
    ];

    /**
     * Initialize appointment booking properties
     */
    public function initializeAppointmentBooking()
    {
        // Can be called in mount() of the component
    }

    /**
     * Validation rules for appointment
     */
    protected function getAppointmentValidationRules()
    {
        $clinicId = Patient::clinicIdForWrite();
        return [
            'appointmentTitle' => 'required|string|min:3',
            'appointmentRecallCategory' => 'nullable|string|max:100',
            'appointmentPatientId' => ['required', Rule::exists('patients', 'id')->where(fn ($query) => $query
                ->where('clinic_id', $clinicId)->whereNull('deleted_at'))],
            'appointmentScheduledAt' => 'required|date|after_or_equal:today',
            'appointmentNotes' => 'nullable|string',
            'appointmentReminderChannel' => 'required|in:none,sms,whatsapp,both',
        ];
    }

    /**
     * Search for patients (debounced search)
     */
    public function updatedAppointmentPatientSearch($value)
    {
        if (strlen($value) >= 2) {
            $this->appointmentSearchablePatients = Patient::where('name', 'like', '%' . $value . '%')
                ->orWhere('pxnumber', 'like', '%' . $value . '%')
                ->take(5)
                ->get();
        } else {
            $this->appointmentSearchablePatients = [];
        }
    }

    /**
     * Select a patient from search results
     */
    public function selectAppointmentPatient($id, $name)
    {
        $this->appointmentPatientId = $id;
        $this->appointmentSelectedPatientName = $name;
        $this->appointmentPatientSearch = '';
        $this->appointmentSearchablePatients = [];
    }

    /**
     * ⭐ NEW METHOD: Book appointment from consultation (used in blade form)
     * This is the method that the blade component calls
     */
    public function bookAppointmentFromConsultation()
    {
        // Validate appointment data
        try {
            $this->validate([
                'appointmentTitle' => 'required|string|min:3',
                'appointmentScheduledAt' => 'required|date|after_or_equal:today',
                'appointmentPatientId' => $this->getAppointmentValidationRules()['appointmentPatientId'],
            ], [
                'appointmentTitle.required' => 'Please enter a reason for the appointment',
                'appointmentScheduledAt.required' => 'Please select a date and time',
                'appointmentScheduledAt.after_or_equal' => 'Appointment date must be today or in the future',
            ]);

            // Create appointment
            $appointment = Appointments::create([
                'patient_id' => $this->appointmentPatientId,
                'user_id' => Auth::id(),
                'title' => $this->appointmentTitle,
                'recall_category' => $this->appointmentRecallCategory ?: $this->appointmentTitle,
                'scheduled_at' => Carbon::parse($this->appointmentScheduledAt),
                'notes' => $this->appointmentNotes,
                'reminder_channel' => $this->appointmentReminderChannel ?: 'whatsapp',
                'reminder_status' => 'not_sent',
                'status' => 'Pending',
            ]);

            app(AppointmentNotifier::class)->confirmed($appointment);

            // SUCCESS - Show notification
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Appointment scheduled for ' . Carbon::parse($this->appointmentScheduledAt)->format('M d, Y \a\t h:i A')]);

            // Reset form fields
            $this->resetAppointmentForm();
            
            // Close the appointment section if it exists (doctor context)
            if (property_exists($this, 'showAppointmentSection')) {
                $this->showAppointmentSection = false;
            }

            // Log success
            \Log::info('Appointment created successfully', [
                'appointment_id' => $appointment->id,
                'patient_id' => $this->appointmentPatientId,
                'scheduled_at' => $this->appointmentScheduledAt,
            ]);

            return true;

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Validation errors will be shown automatically by Livewire
            throw $e;
            
        } catch (\Exception $e) {
            // ERROR - Show notification
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Failed to create appointment: ' . $e->getMessage()]);
            \Log::error('Appointment Booking Error', [
                'patient_id' => $this->appointmentPatientId ?? 'null',
                'title' => $this->appointmentTitle ?? 'null',
                'scheduled_at' => $this->appointmentScheduledAt ?? 'null',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        }
    }

    /**
     * Create a new appointment (legacy method - kept for backward compatibility)
     * 
     * @param int|null $overridePatientId - Optional: override patient ID (useful in doctor context)
     * @return bool
     */
    public function createAppointment($overridePatientId = null)
    {
        // Use override patient ID if provided, otherwise use selected patient
        $patientId = $overridePatientId ?? $this->appointmentPatientId;
        
        // Validate
        $rules = $this->getAppointmentValidationRules();
        if ($overridePatientId !== null) {
            $this->appointmentPatientId = $overridePatientId;
        }
        $this->validate($rules);

        try {
            $appointment = Appointments::create([
                'patient_id' => $patientId,
                'user_id' => Auth::id(),
                'title' => $this->appointmentTitle,
                'recall_category' => $this->appointmentRecallCategory ?: $this->appointmentTitle,
                'scheduled_at' => Carbon::parse($this->appointmentScheduledAt),
                'notes' => $this->appointmentNotes,
                'reminder_channel' => $this->appointmentReminderChannel ?: 'whatsapp',
                'reminder_status' => 'not_sent',
                'status' => 'Pending',
            ]);

            app(AppointmentNotifier::class)->confirmed($appointment);

            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Appointment scheduled successfully for ' . Carbon::parse($this->appointmentScheduledAt)->format('M d, Y h:i A')]);

            $this->resetAppointmentForm();
            return true;

        } catch (\Exception $e) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Failed to create appointment: ' . $e->getMessage()]);
            \Log::error('Appointment creation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset appointment form
     */
    public function resetAppointmentForm()
    {
        $this->appointmentTitle = null;
        $this->appointmentRecallCategory = null;
        $this->appointmentScheduledAt = null;
        $this->appointmentNotes = null;
        $this->appointmentReminderChannel = 'whatsapp';
        $this->appointmentPatientId = null;
        $this->appointmentPatientSearch = '';
        $this->appointmentSelectedPatientName = '';
        $this->appointmentSearchablePatients = [];
        $this->resetValidation();
    }

    /**
     * Auto-fill appointment from consultation context
     * 
     * @param Patient $patient
     * @param string|null $reason - Pre-fill appointment reason
     * @param \DateTime|null $suggestedDate - Pre-fill date (e.g., nextvisit date)
     */
    public function prefillAppointmentFromConsultation($patient, $reason = null, $suggestedDate = null)
    {
        $this->appointmentPatientId = $patient->id;
        $this->appointmentSelectedPatientName = $patient->name;
        
        if ($reason) {
            $this->appointmentTitle = $reason;
        }
        
        if ($suggestedDate) {
            $this->appointmentScheduledAt = Carbon::parse($suggestedDate)->format('Y-m-d\TH:i');
        }
    }

    /**
     * Quick book follow-up appointment based on consultation's nextvisit
     * 
     * @param int $consultationId
     */
    public function quickBookFollowUp($consultationId)
    {
        $consultation = \App\Models\Consultations::with('patient')->find($consultationId);
        
        if (!$consultation) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Consultation not found']);
            return;
        }

        // Pre-fill the form
        $this->prefillAppointmentFromConsultation(
            $consultation->patient,
            'Follow-up Visit',
            $consultation->nextvisit
        );

        $this->dispatch('notify', ...['type' => 'info', 'message' => 'Appointment form pre-filled. Please review and save.']);
    }

    /**
     * Get computed property for appointment reasons (for components that use this trait)
     */
    public function getAppointmentReasonsProperty()
    {
        return $this->appointmentReasons;
    }

    public function getAppointmentRecallCategoriesProperty()
    {
        return $this->appointmentRecallCategories;
    }
}
