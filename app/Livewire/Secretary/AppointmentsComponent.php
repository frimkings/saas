<?php

namespace App\Livewire\Secretary;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Appointments;
use App\Models\Patient;
use App\Models\User;
use App\Models\OnlineBooking;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use App\Livewire\Traits\HasAppointmentBooking;
use App\Services\SmsService;
use App\Services\Messaging\AppointmentNotifier;
use App\Support\Messaging\DefaultSmsTemplates;
use App\Support\Messaging\SmsAvailability;
use App\Support\Messaging\SmsSegments;
use App\Support\Messaging\WhatsAppLink;
use App\Models\SmsTemplate;
use Illuminate\Validation\Rule;
class AppointmentsComponent extends Component
{
    use WithPagination;
    use HasAppointmentBooking;
    // --- Appointment Intake Properties ---
    public $title;
    public $recall_category;
    public $reminder_channel = 'whatsapp';
    public $patient_id;
    public $scheduled_at;
    public $doctor_id;
    public $duration_minutes = 30;
    public $notes;
    public $patientSearch = '';
    public $selectedPatientName = '';

    // --- UI & Navigation Properties ---
    public $search = '';
    public $activeFilter = 'schedule';
    public $scheduleView = 'list';
    public $editingAppointmentId = null;
    public $isEditModalOpen = false;
    public $newAppointmentStatus = 'Pending';
    public $selectedScheduleDate;
    
    // --- Selection Properties ---
    public $selectedAppointments = []; 
    public $selectAll = false;

    // --- Range Filtering Properties ---
    public $startDate;
    public $endDate;
    public $statusFilter = 'All';
    public $dailyAppointmentLimit = 30;
    public $calendarStartDate;

    // --- Quick Filter & Cancel ---
    public $quickFilter       = '';
    public $cancelReason      = '';
    public $cancellingId      = null;
    public $isCancelModalOpen = false;

    // --- Missed Follow-up ---
    public $missedActionId  = null;
    public $rescheduleDate  = '';
    public $rescheduleTime  = '09:00';


public $recallCategories = [
    'Routine Review',
    'Glaucoma Review',
    'Diabetic Review',
    'Post-op Review',
    'Glasses Pickup',
];


    // WhatsApp link for the booking just made, shown until staff open or dismiss it.
    public ?string $confirmationWhatsAppUrl = null;
    public ?string $confirmationPatientName = null;
    /** What the WhatsApp banner offers to send: booked, rescheduled or cancelled. */
    public string $confirmationKind = 'booked';

    // --- Bulk follow-up SMS for missed appointments ---
    /** Most texts one click sends: longer runs could time out without a queue worker. */
    public const BULK_FOLLOW_UP_LIMIT = 100;
    /** Missed tab quick filter: all | not_followed | recent (last 7 days). */
    public string $missedView = 'all';
    /** The confirm step: who will be texted, who is skipped and why, and the credits needed. */
    public ?array $bulkFollowUpPlan = null;
    // Message wording per template key, loaded once per request.
    private array $templateText = [];
    public $showReminderPreview = false;
    public $previewReminderMessage = '';
    public $previewReminderPhone = '';
    public $previewReminderChannel = 'whatsapp';
    public $previewAppointmentId = null;

    protected function rules(): array
    {
        $context = app(\App\Support\Tenancy\TenantContext::class);
        $clinicId = Patient::clinicIdForWrite();
        $branchId = $context->branchId();

        $doctorRule = $branchId
            ? Rule::exists('branch_user', 'user_id')->where(fn ($query) => $query
                ->where('branch_id', $branchId)->where('status', 'active'))
            : Rule::exists('users', 'id');

        return [
            'title' => 'required|string|min:3',
            'recall_category' => 'nullable|string|max:100',
            'reminder_channel' => 'required|in:none,sms,whatsapp,both',
            'patient_id' => ['required', Rule::exists('patients', 'id')->where(fn ($query) => $query
                ->where('clinic_id', $clinicId)->whereNull('deleted_at'))],
            'scheduled_at' => 'required|date|after_or_equal:today',
            'doctor_id' => ['nullable', $doctorRule],
            'duration_minutes' => 'required|integer|in:15,30,45,60,90,120',
            'notes' => 'nullable|string',
        ];
    }

    protected $messages = [
        'scheduled_at.after_or_equal' => 'Appointment date cannot be in the past.',
    ];

    public function mount()
    {
        if (!in_array('Walk-in Visit', $this->appointmentReasons, true)) {
            array_unshift($this->appointmentReasons, 'Walk-in Visit');
        }

        $settings = \App\Models\Setting::getSettings();

        $this->startDate = Carbon::now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $this->endDate = Carbon::now()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d');
        $this->calendarStartDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->selectedScheduleDate = Carbon::today()->format('Y-m-d');
        $this->markPastAppointmentsAsMissed();
    }

    public function updatedActiveFilter()
    {
        $this->resetSelection();
        $this->quickFilter = '';
        $this->bulkFollowUpPlan = null;
        $this->resetPage();
    }

    public function updatedScheduleView()
    {
        $this->resetSelection();
        $this->resetPage();
    }

    public function updatedSearch() 
    { 
        $this->resetPage(); 
    }

    public function previousCalendarWeek(): void
    {
        $this->calendarStartDate = Carbon::parse($this->calendarStartDate)->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
    }

    public function nextCalendarWeek(): void
    {
        $this->calendarStartDate = Carbon::parse($this->calendarStartDate)->addMonthNoOverflow()->startOfMonth()->format('Y-m-d');
    }

    public function goToCurrentCalendarWeek(): void
    {
        $this->calendarStartDate = Carbon::now()->startOfMonth()->format('Y-m-d');
    }

    public function previousScheduleDay(): void
    {
        $this->selectedScheduleDate = Carbon::parse($this->selectedScheduleDate)->subDay()->format('Y-m-d');
    }

    public function nextScheduleDay(): void
    {
        $this->selectedScheduleDate = Carbon::parse($this->selectedScheduleDate)->addDay()->format('Y-m-d');
    }

    public function goToTodaySchedule(): void
    {
        $this->selectedScheduleDate = Carbon::today()->format('Y-m-d');
        $this->calendarStartDate = Carbon::now()->startOfMonth()->format('Y-m-d');
    }

    public function bookOnCalendarDate(string $date): void
    {
        if (Carbon::parse($date)->isPast() && !Carbon::parse($date)->isToday()) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Cannot book an appointment on a past date.']);
            return;
        }

        $time = now()->format('H:i');
        $this->scheduled_at = Carbon::parse($date . ' ' . $time)->format('Y-m-d\TH:i');
        $this->editingAppointmentId = null;
        $this->newAppointmentStatus = 'Pending';
        $this->isEditModalOpen = true;
    }

    public function rescheduleAppointment($appointmentId, string $scheduledAt): void
    {
        $scheduledAt = Carbon::parse($scheduledAt);

        if ($scheduledAt->isPast() && !$scheduledAt->isToday()) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Cannot reschedule to a past date.']);
            return;
        }

        $appointment = Appointments::findOrFail($appointmentId);
        $moved = ! $appointment->scheduled_at->equalTo($scheduledAt);
        $appointment->update([
            'scheduled_at' => $scheduledAt,
            'status' => 'Rescheduled',
        ]);
        if ($moved) $this->tellPatient($appointment, 'rescheduled');

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => 'Appointment moved to ' . $scheduledAt->format('M d, Y h:i A') . '.',
        ]);
    }

    public function openNewAppointmentModal(): void
    {
        $this->resetForm();
        $this->editingAppointmentId = null;
        $this->newAppointmentStatus = 'Pending';
        $this->scheduled_at = now()->format('Y-m-d\TH:i');
        $this->isEditModalOpen = true;
    }

    public function openWalkInModal(): void
    {
        $this->resetForm();
        $this->editingAppointmentId = null;
        $this->newAppointmentStatus = 'Arrived';
        $this->title = 'Walk-in Visit';
        $this->recall_category = 'Routine Review';
        $this->reminder_channel = 'none';
        $this->scheduled_at = now()->format('Y-m-d\TH:i');
        $this->isEditModalOpen = true;
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedAppointments = $this->getFilteredQuery()
                ->pluck('id')
                ->map(fn($id) => (string)$id)
                ->toArray();
        } else {
            $this->selectedAppointments = [];
        }
    }

    public function resetSelection()
    {
        $this->selectedAppointments = [];
        $this->selectAll = false;
    }

  
    public function closeClinicDay()
    {
        $unfinishedStatuses = ['Pending', 'Called', 'Couldnt Answer', 'Rescheduled'];

        $count = \DB::transaction(function () use ($unfinishedStatuses) {
            $todayAppointments = Appointments::whereDateIndexed('scheduled_at', Carbon::today())
                ->whereIn('status', $unfinishedStatuses)
                ->get();

            foreach ($todayAppointments as $appointment) {
                $appointment->update([
                    'scheduled_at' => Carbon::parse($appointment->scheduled_at)->addDay(),
                    'status'       => 'Pending',
                    'notes'        => $appointment->notes . "\n[Auto-moved from " . Carbon::today()->format('M d') . "]",
                ]);
            }

            return $todayAppointments->count();
        });

        $this->dispatch('notify', ...['type' => 'success', 'message' => "Day Closed. $count unfinished appointments moved to tomorrow."]);
    }

    /**
     * Bulk Actions
     */
    public function bulkMarkAsSeen()
    {
        if (empty($this->selectedAppointments)) return;
        Appointments::whereIn('id', $this->selectedAppointments)->update(['status' => 'Seen', 'completed_at' => now()]);
        $count = count($this->selectedAppointments);
        $this->resetSelection();
        $this->dispatch('notify', ...['type' => 'success', 'message' => "Successfully moved $count records to History."]);
    }

    public function bulkMarkRemindersSent()
    {
        if (empty($this->selectedAppointments)) return;
        Appointments::whereIn('id', $this->selectedAppointments)->update([
            'reminder_status' => 'sent',
            'reminder_sent_at' => now(),
        ]);

        $count = count($this->selectedAppointments);
        $this->resetSelection();
        $this->dispatch('notify', ...['type' => 'success', 'message' => "$count reminder(s) marked as sent."]);
    }

    public function bulkDelete()
    {
        if (empty($this->selectedAppointments)) return;
        Appointments::whereIn('id', $this->selectedAppointments)->delete();
        $count = count($this->selectedAppointments);
        $this->resetSelection();
        $this->dispatch('notify', ...['type' => 'success', 'message' => "Successfully moved $count records to Trash."]);
    }

    /**
     * Data Export
     */
  public function exportReport()
{
    $query = $this->getFilteredQuery();
    $appointments = $query->get();
    $filename = "clinic-registry-" . now()->format('Y-m-d') . ".csv";
    
    $headers = [
        "Content-type" => "text/csv",
        "Content-Disposition" => "attachment; filename=$filename",
    ];

    $callback = function() use($appointments) {
        $file = fopen('php://output', 'w');
        // Added 'Category' to the header row
        fputcsv($file, ['Date', 'Time', 'Patient Name', 'Contact', 'Reason', 'Recall Category', 'Reminder Channel', 'Reminder Status', 'Status', 'Notes']);
        
        foreach ($appointments as $app) {
            fputcsv($file, [
                $app->scheduled_at->format('Y-m-d'),
                $app->scheduled_at->format('h:i A'),
                $app->patient->name,
                $app->patient->contact ?? 'N/A',
                $app->title,
                $app->recall_category ?? $this->getCategoryByReason($app->title),
                $app->reminder_channel ?? 'whatsapp',
                $app->reminder_status ?? 'not_sent',
                $app->status,
                $app->notes ?? ''
            ]);
        }
        fclose($file);
    };

    return Response::stream($callback, 200, $headers);
}

    private function getFilteredQuery()
    {
        $query = ($this->activeFilter === 'trash') 
            ? Appointments::onlyTrashed()->with(['patient', 'doctor'])
            : Appointments::with(['patient', 'doctor']);
        
        $query->whereHas('patient', function ($q) {
            $q->where(function ($sub) {
                $sub->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('pxnumber', 'like', '%' . $this->search . '%')
                    ->orWhere('contact', 'like', '%' . $this->search . '%');
            });
        });

        // Tabs logic
        if ($this->activeFilter === 'history') {
            $query->whereIn('status', ['Seen', 'Cancelled']);
        } elseif ($this->activeFilter === 'queue') {
            $query->whereDateIndexed('scheduled_at', Carbon::today())
                ->whereNotIn('status', ['Seen', 'Missed', 'Cancelled']);
        } elseif ($this->activeFilter === 'missed') {
            $query->where('status', 'Missed')
                ->when($this->missedView === 'not_followed', fn ($q) => $q->whereNull('missed_followup_sent_at'))
                ->when($this->missedView === 'recent', fn ($q) => $q->where('scheduled_at', '>=', Carbon::today()->subDays(7)));
        } elseif ($this->activeFilter !== 'trash'
            && $this->activeFilter !== 'settings'
            && !($this->activeFilter === 'schedule' && $this->scheduleView === 'range' && $this->statusFilter !== 'All')) {
            $query->whereNotIn('status', ['Seen', 'Missed', 'Cancelled']);
        }

        switch ($this->activeFilter) {
            case 'schedule':
                if ($this->scheduleView === 'range') {
                    $query->whereBetween('scheduled_at', [
                        Carbon::parse($this->startDate)->startOfDay(),
                        Carbon::parse($this->endDate)->endOfDay(),
                    ]);
                    if ($this->statusFilter !== 'All') {
                        $query->where('status', $this->statusFilter);
                    }
                } else {
                    $query->where('scheduled_at', '>=', Carbon::today()->startOfDay());
                    // Quick filter chips
                    if ($this->quickFilter === 'today') {
                        $query->whereDateIndexed('scheduled_at', Carbon::today());
                    } elseif ($this->quickFilter === 'tomorrow') {
                        $query->whereDateIndexed('scheduled_at', Carbon::tomorrow());
                    } elseif ($this->quickFilter === 'this_week') {
                        $query->whereBetween('scheduled_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                    } elseif ($this->quickFilter === 'next_30') {
                        $query->whereBetween('scheduled_at', [Carbon::today(), Carbon::today()->addDays(30)]);
                    }
                }
                break;
            case 'queue':
                $query->orderByRaw("FIELD(status, 'Arrived', 'With Doctor', 'Pending', 'Called', 'Couldnt Answer', 'Rescheduled', 'Done')")
                    ->orderBy('scheduled_at', 'asc');
                break;
            case 'history':
                $query->orderBy('scheduled_at', 'desc');
                break;
            case 'missed':
                $query->orderBy('scheduled_at', 'desc');
                break;
        }
        
        return $query;
    }

    public function updateStatus($id, $status)
    {
        $allowed = ['Pending', 'Confirmed', 'Called', 'Couldnt Answer', 'Rescheduled', 'Arrived', 'With Doctor', 'Done', 'Seen', 'Missed', 'Cancelled'];
        if (! in_array($status, $allowed, true)) {
            $this->dispatch('notify', type: 'error', message: 'Invalid appointment status.');
            return;
        }
        $appointment = Appointments::findOrFail($id);
        if (in_array($appointment->status, ['Seen', 'Cancelled'], true) && $appointment->status !== $status) {
            $this->dispatch('notify', type: 'warning', message: 'This appointment is closed and cannot be moved back into the queue.');
            return;
        }
        $payload = ['status' => $status];
        $payload['missed_at'] = $status === 'Missed' ? now() : null;
        if ($status === 'Arrived') $payload['arrived_at'] = now();
        if ($status === 'With Doctor') $payload['doctor_started_at'] = now();
        if (in_array($status, ['Done', 'Seen'], true)) $payload['completed_at'] = now();

        $wasCancelled = $appointment->status === 'Cancelled';
        $appointment->update($payload);
        if ($status === 'Cancelled' && ! $wasCancelled) $this->tellPatient($appointment, 'cancelled');
        $this->dispatch('notify', ...['type' => 'success', 'message' => ($status === 'Seen') ? "Moved to History." : "Status updated."]);
    }

    public function advanceQueueStatus($id, $status)
    {
        $allowed = ['Arrived', 'With Doctor', 'Done'];

        if (!in_array($status, $allowed, true)) {
            return;
        }

        $this->updateStatus($id, $status);
    }

    public function quickBookWalkIn()
    {
        $clinicId = Patient::clinicIdForWrite();
        $this->validate([
            'patient_id' => ['required', Rule::exists('patients', 'id')->where(fn ($query) => $query
                ->where('clinic_id', $clinicId)->whereNull('deleted_at'))],
            'title' => 'nullable|string|min:3',
            'recall_category' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'reminder_channel' => 'required|in:none,sms,whatsapp,both',
        ]);

        $scheduledAt = now();
        $this->flashDailyLimitWarning($scheduledAt);

        Appointments::create([
            'patient_id' => $this->patient_id,
            'user_id' => Auth::id(),
            'title' => $this->title ?: 'Walk-in Visit',
            'recall_category' => $this->recall_category ?: ($this->title ?: 'Walk-in Visit'),
            'scheduled_at' => $scheduledAt,
            'doctor_id' => $this->doctor_id ?: null,
            'duration_minutes' => (int) $this->duration_minutes,
            'notes' => $this->notes,
            'reminder_channel' => $this->reminder_channel ?: 'none',
            'reminder_status' => 'not_sent',
            'status' => 'Arrived',
            'arrived_at' => now(),
        ]);

        $this->resetForm();
        $this->activeFilter = 'queue';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Walk-in booked and added to the waiting room.']);
    }

    public function markReminderSent($id, $channel)
    {
        Appointments::findOrFail($id)->update([
            'reminder_channel' => $channel,
            'reminder_status' => 'sent',
            'reminder_sent_at' => now(),
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => ucfirst($channel) . ' reminder marked as sent.']);
    }

    public function sendSmsNow($id): void
    {
        $appointment = Appointments::with('patient')->findOrFail($id);
        $phone = $appointment->patient->contact ?? '';

        if (empty(trim($phone))) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'No contact number on record for this patient.']);
            return;
        }

        $message = $this->reminderMessage($appointment);

        $result = (new SmsService)->send($phone, $message, $appointment->patient->id, 'appointment_reminder');

        if ($result['success']) {
            Appointments::findOrFail($id)->update(['reminder_status' => 'sent', 'reminder_sent_at' => now()]);
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'SMS sent successfully.']);
        } else {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'SMS failed: ' . ($result['error'] ?? 'Unknown error')]);
        }
    }

    public function previewReminder($id, $channel = 'whatsapp')
    {
        $appointment = Appointments::with('patient')->findOrFail($id);
        $this->previewReminderMessage = $this->reminderMessage($appointment);
        $this->previewReminderPhone = $appointment->patient->contact ?? '';
        $this->previewReminderChannel = $channel;
        $this->previewAppointmentId = $appointment->id;
        $this->showReminderPreview = true;
    }

    public function closeReminderPreview()
    {
        $this->showReminderPreview = false;
        $this->previewReminderMessage = '';
        $this->previewReminderPhone = '';
        $this->previewAppointmentId = null;
    }

    public function markPreviewReminderSent()
    {
        if ($this->previewAppointmentId) {
            $this->markReminderSent($this->previewAppointmentId, $this->previewReminderChannel);
        }

        $this->closeReminderPreview();
    }

    public function getPreviewWhatsAppUrlProperty()
    {
        return WhatsAppLink::to($this->previewReminderPhone, $this->previewReminderMessage) ?? '#';
    }

    /** Reminder wording from the clinic's "Appointment Reminder" template (Communications → Messages). */
    public function reminderMessage(Appointments $appointment): string
    {
        return $this->appointmentMessage('appointment_reminder', $appointment);
    }

    public function reminderWhatsAppUrl(Appointments $appointment): ?string
    {
        return WhatsAppLink::to($appointment->patient?->contact, $this->reminderMessage($appointment));
    }

    public function missedFollowUpMessage(Appointments $appointment): string
    {
        return $this->appointmentMessage('appointment_missed_followup', $appointment);
    }

    public function missedFollowUpWhatsAppUrl(Appointments $appointment): ?string
    {
        return WhatsAppLink::to($appointment->patient?->contact, $this->missedFollowUpMessage($appointment));
    }

    public function dismissConfirmationWhatsApp(): void
    {
        $this->confirmationWhatsAppUrl = null;
        $this->confirmationPatientName = null;
        $this->confirmationKind = 'booked';
    }

    /**
     * Tell the patient an upcoming appointment moved or was cancelled (AppointmentNotifier picks
     * SMS or, for WhatsApp bookings, a pre-filled chat offered on screen). A move also re-arms
     * the 24-hour reminder so it goes out again for the new date.
     */
    private function tellPatient(Appointments $appointment, string $kind): void
    {
        if ($kind === 'rescheduled') {
            $appointment->forceFill(['reminder_sent_at' => null, 'reminder_status' => 'not_sent'])->save();
        }

        $appointment->loadMissing('patient');
        $notifier = app(AppointmentNotifier::class);
        $message = $kind === 'rescheduled' ? $notifier->rescheduled($appointment) : $notifier->cancelled($appointment);
        if ($message && in_array($appointment->reminder_channel, ['whatsapp', 'both'], true)) {
            $this->confirmationWhatsAppUrl = WhatsAppLink::to($appointment->patient?->contact, $message);
            $this->confirmationPatientName = $appointment->patient?->name;
            $this->confirmationKind = $kind;
        }
    }

    private function appointmentMessage(string $key, Appointments $appointment): string
    {
        $this->templateText[$key] ??= SmsTemplate::where('key', $key)->value('message')
            ?? DefaultSmsTemplates::message($key) ?? '';

        return SmsTemplate::fillMessage($this->templateText[$key], [
            '[NAME]'   => $appointment->patient?->name ?? '',
            '[REASON]' => $appointment->title,
            '[DATE]'   => $appointment->scheduled_at->format('M d, Y'),
            '[TIME]'   => $appointment->scheduled_at->format('h:i A'),
        ], $appointment->branch_id ? $appointment->branch : null);
    }

    private function getCategoryByReason($reason): string
    {
        $reason = strtolower((string) $reason);

        if (str_contains($reason, 'glaucoma')) {
            return 'Glaucoma Review';
        }

        if (str_contains($reason, 'diabetic') || str_contains($reason, 'diabetes')) {
            return 'Diabetic Review';
        }

        if (str_contains($reason, 'post-op') || str_contains($reason, 'post op') || str_contains($reason, 'surgery')) {
            return 'Post-op Review';
        }

        if (str_contains($reason, 'glasses') || str_contains($reason, 'pickup')) {
            return 'Glasses Pickup';
        }

        return 'Routine Review';
    }

    private function markPastAppointmentsAsMissed(): void
    {
        Appointments::markPastAsMissed();
    }

    public function getCountsProperty()
    {
        return [
            'schedule' => Appointments::where('scheduled_at', '>=', Carbon::today()->startOfDay())->whereNotIn('status', ['Seen', 'Missed', 'Cancelled'])->count(),
            'online'   => OnlineBooking::where('status', 'pending')->count(),
            'queue'    => Appointments::whereDateIndexed('scheduled_at', Carbon::today())->whereNotIn('status', ['Seen', 'Missed', 'Cancelled'])->count(),
            'history'  => Appointments::whereIn('status', ['Seen', 'Cancelled'])->count(),
            'missed'   => Appointments::where('status', 'Missed')->count(),
            'trash'    => Appointments::onlyTrashed()->count(),
        ];
    }

    public function convertOnlineBooking($id)
    {
        $booking = OnlineBooking::findOrFail($id);
        $controller = new \App\Http\Controllers\Api\PublicBookingController();
        $request = new \Illuminate\Http\Request();
        $response = $controller->convert($request, $booking);
        $responseData = json_decode($response->getContent(), true);

        $pxnumber = $responseData['data']['pxnumber'] ?? '';

        $this->dispatch('notify', ...[
            'type' => 'success',
            'message' => "Online booking #{$id} converted to Patient ({$pxnumber}) & Scheduled on Calendar!",
        ]);
    }

    public function cancelOnlineBooking($id)
    {
        $booking = OnlineBooking::findOrFail($id);
        $booking->update(['status' => 'cancelled']);

        $this->dispatch('notify', ...[
            'type' => 'info',
            'message' => 'Online booking request cancelled.',
        ]);
    }

    public function getCalendarWeeksProperty()
    {
        $cursor = Carbon::parse($this->calendarStartDate)->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = Carbon::parse($this->calendarStartDate)->endOfMonth();
        $weeks = collect();

        while ($cursor->lte($end)) {
            $week = collect();

            foreach (range(0, 4) as $offset) {
                $week->push($cursor->copy()->addDays($offset));
            }

            $weeks->push($week);
            $cursor->addWeek();
        }

        return $weeks;
    }

    public function getCalendarAppointmentsProperty()
    {
        $start = Carbon::parse($this->calendarStartDate)->startOfMonth()->startOfDay();
        $end = Carbon::parse($this->calendarStartDate)->endOfMonth()->endOfDay();

        return Appointments::with('patient')
            ->whereBetween('scheduled_at', [$start, $end])
            ->whereNotIn('status', ['Seen', 'Missed'])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn ($appointment) => $appointment->scheduled_at->format('Y-m-d'));
    }

    public function getDayTimeSlotsProperty()
    {
        $day = Carbon::parse($this->selectedScheduleDate);

        return collect(range(8, 17))->map(function ($hour) use ($day) {
            return $day->copy()->hour($hour)->minute(0)->second(0);
        });
    }

    public function getDayAppointmentsProperty()
    {
        return Appointments::with('patient')
            ->whereDateIndexed('scheduled_at', Carbon::parse($this->selectedScheduleDate))
            ->whereNotIn('status', ['Seen', 'Missed'])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn ($appointment) => $appointment->scheduled_at->format('H:00'));
    }

    public function getStatusSummaryProperty()
    {
        return [
            'Booked' => Appointments::whereDateIndexed('scheduled_at', Carbon::today())->whereNotIn('status', ['Seen', 'Missed'])->count(),
            'Arrived' => Appointments::whereDateIndexed('scheduled_at', Carbon::today())->where('status', 'Arrived')->count(),
            'With Doctor' => Appointments::whereDateIndexed('scheduled_at', Carbon::today())->where('status', 'With Doctor')->count(),
            'Seen' => Appointments::whereDateIndexed('scheduled_at', Carbon::today())->where('status', 'Seen')->count(),
            'Missed' => Appointments::whereDateIndexed('scheduled_at', Carbon::today())->where('status', 'Missed')->count(),
        ];
    }

    public function selectPatient($id, $name) 
    { 
        $this->patient_id = $id; 
        $this->selectedPatientName = $name; 
        $this->patientSearch = ''; 
    }

    public function clearSelectedPatient(): void
    {
        $this->patient_id = null;
        $this->selectedPatientName = '';
        $this->patientSearch = '';
    }

    public function saveAppointment()
    {
        $this->validate();
        $scheduledAt = Carbon::parse($this->scheduled_at);

        if ($this->hasSchedulingConflict($scheduledAt)) {
            return;
        }
        
        $payload = [
            'patient_id' => $this->patient_id, 
            'title' => $this->title, 
            'recall_category' => $this->recall_category ?: $this->title,
            'scheduled_at' => $scheduledAt,
            'doctor_id' => $this->doctor_id ?: null,
            'duration_minutes' => (int) $this->duration_minutes,
            'notes' => $this->notes,
            'reminder_channel' => $this->reminder_channel ?: 'whatsapp',
        ];

        if ($this->editingAppointmentId) {
            $apt = Appointments::withTrashed()->findOrFail($this->editingAppointmentId);
            if ($apt->trashed()) {
                $apt->restore();
            }
            $moved = ! $apt->scheduled_at->equalTo($scheduledAt);
            $apt->update($payload);
            if ($moved && ! in_array($apt->status, ['Cancelled', 'Seen', 'Done'], true)) $this->tellPatient($apt, 'rescheduled');
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Record updated.']);
        } else {
            $this->flashDailyLimitWarning($scheduledAt);

            $appointment = Appointments::create(array_merge($payload, [
                'user_id' => Auth::id(),
                'reminder_status' => 'not_sent',
                'status' => $this->newAppointmentStatus ?: 'Pending'
            ]));
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Booking added.']);

            // "WhatsApp" bookings skip the SMS (saving a credit) and offer a pre-filled WhatsApp chat instead.
            $channel = $appointment->reminder_channel;
            $message = app(AppointmentNotifier::class)->confirmed($appointment, sendSms: $channel !== 'whatsapp');
            if (in_array($channel, ['whatsapp', 'both'], true) && $message) {
                $this->confirmationWhatsAppUrl = WhatsAppLink::to($appointment->patient?->contact, $message);
                $this->confirmationPatientName = $appointment->patient?->name;
                $this->confirmationKind = 'booked';
            }
        }

        $this->closeModal();
    }

    public function editAppointment($id)
    {
        $this->editingAppointmentId = $id;
        $appointment = Appointments::withTrashed()->with('patient')->findOrFail($id);
        $this->patient_id = $appointment->patient_id;
        $this->selectedPatientName = $appointment->patient->name;
        $this->title = $appointment->title;
        $this->recall_category = $appointment->recall_category;
        $this->reminder_channel = $appointment->reminder_channel ?? 'whatsapp';
        $this->notes = $appointment->notes;
        $this->scheduled_at = Carbon::parse($appointment->scheduled_at)->format('Y-m-d\TH:i');
        $this->doctor_id = $appointment->doctor_id;
        $this->duration_minutes = $appointment->duration_minutes ?: 30;
        $this->isEditModalOpen = true;
    }

    public function restoreAppointment($id) { Appointments::onlyTrashed()->findOrFail($id)->restore(); $this->dispatch('notify', ...['type' => 'success', 'message' => 'Restored.']); }

    public function closeModal() 
    { 
        $this->resetForm(); 
        $this->isEditModalOpen = false; 
        $this->editingAppointmentId = null; 
        $this->newAppointmentStatus = 'Pending';
    }

    public function resetForm() { $this->reset(['title', 'recall_category', 'patient_id', 'doctor_id', 'scheduled_at', 'notes', 'selectedPatientName', 'patientSearch']); $this->duration_minutes = 30; $this->reminder_channel = 'whatsapp'; $this->newAppointmentStatus = 'Pending'; }

    private function hasSchedulingConflict(Carbon $scheduledAt): bool
    {
        $endAt = $scheduledAt->copy()->addMinutes((int) $this->duration_minutes);
        $appointments = Appointments::whereDateIndexed('scheduled_at', $scheduledAt->toDateString())
            ->whereNotIn('status', ['Cancelled', 'Missed'])
            ->when($this->editingAppointmentId, fn ($query) => $query->where('id', '!=', $this->editingAppointmentId))
            ->get(['id', 'patient_id', 'doctor_id', 'scheduled_at', 'duration_minutes']);

        $patientConflict = $appointments->first(function ($appointment) use ($scheduledAt, $endAt) {
            if ((int) $appointment->patient_id !== (int) $this->patient_id) return false;
            $existingEnd = $appointment->scheduled_at->copy()->addMinutes($appointment->duration_minutes ?: 30);
            return $appointment->scheduled_at->lt($endAt) && $existingEnd->gt($scheduledAt);
        });
        if ($patientConflict) {
            $this->addError('scheduled_at', 'This patient already has an overlapping appointment.');
            return true;
        }

        if ($this->doctor_id) {
            $doctorConflict = $appointments->first(function ($appointment) use ($scheduledAt, $endAt) {
                if ((int) $appointment->doctor_id !== (int) $this->doctor_id) return false;
                $existingEnd = $appointment->scheduled_at->copy()->addMinutes($appointment->duration_minutes ?: 30);
                return $appointment->scheduled_at->lt($endAt) && $existingEnd->gt($scheduledAt);
            });
            if ($doctorConflict) {
                $this->addError('scheduled_at', 'The selected doctor already has an overlapping appointment.');
                return true;
            }
        }
        return false;
    }

    private function flashDailyLimitWarning(Carbon $scheduledAt): void
    {
        $limit = max(1, (int) $this->dailyAppointmentLimit);
        $bookedCount = Appointments::whereDateIndexed('scheduled_at', $scheduledAt->toDateString())->count();

        if ($bookedCount >= $limit) {
            $message = "Daily appointment limit reached for {$scheduledAt->format('M d, Y')} ({$bookedCount}/{$limit}).";

            $this->dispatch('notify', ...[
                'type' => 'warning',
                'message' => $message,
            ]);
        }
    }

    private function noShowStatsFor(Collection $appointments): array
    {
        $patientIds = $appointments->pluck('patient_id')->filter()->unique();

        if ($patientIds->isEmpty()) {
            return [];
        }

        return Appointments::whereIn('patient_id', $patientIds)
            ->where('scheduled_at', '<', now())
            ->get(['patient_id', 'status'])
            ->groupBy('patient_id')
            ->map(function ($rows) {
                $total = $rows->count();
                $missed = $rows->where('status', 'Missed')->count();
                $rate = $total > 0 ? round(($missed / $total) * 100) : 0;
                $class = $rate >= 30 ? 'danger' : ($rate >= 15 ? 'warning' : 'success');

                return compact('total', 'missed', 'rate', 'class');
            })
            ->all();
    }

    public function setQuickFilter(string $filter): void
    {
        $this->quickFilter = ($this->quickFilter === $filter) ? '' : $filter;
        $this->resetPage();
    }

    public function confirmAppointment(int $id): void
    {
        Appointments::findOrFail($id)->update(['status' => 'Confirmed']);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Appointment confirmed.']);
    }

    public function openCancelModal(int $id): void
    {
        $this->cancellingId      = $id;
        $this->cancelReason      = '';
        $this->isCancelModalOpen = true;
    }

    public function closeCancelModal(): void
    {
        $this->cancellingId      = null;
        $this->cancelReason      = '';
        $this->isCancelModalOpen = false;
    }

    public function confirmCancelAppointment(): void
    {
        if (!$this->cancellingId) return;
        $apt = Appointments::findOrFail($this->cancellingId);
        $wasCancelled = $apt->status === 'Cancelled';
        $apt->update([
            'status' => 'Cancelled',
            'notes'  => trim($apt->notes . "\n[Cancelled: " . ($this->cancelReason ?: 'No reason given') . "]"),
        ]);
        if (! $wasCancelled) $this->tellPatient($apt, 'cancelled');
        $this->closeCancelModal();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Appointment cancelled.']);
    }

    public function dragDropReschedule(int $appointmentId, string $newDate): void
    {
        $apt = Appointments::findOrFail($appointmentId);
        $newScheduledAt = Carbon::parse($newDate . ' ' . Carbon::parse($apt->scheduled_at)->format('H:i'));

        if ($newScheduledAt->isPast() && !$newScheduledAt->isToday()) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Cannot reschedule to a past date.']);
            return;
        }

        $moved = ! $apt->scheduled_at->equalTo($newScheduledAt);
        $apt->update(['scheduled_at' => $newScheduledAt, 'status' => 'Rescheduled']);
        if ($moved) $this->tellPatient($apt, 'rescheduled');
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Moved to ' . $newScheduledAt->format('M d, Y') . '.']);
    }

    public function openMissedAction(int $id): void
    {
        $this->missedActionId = $id;
        $this->rescheduleDate = Carbon::tomorrow()->format('Y-m-d');
        $this->rescheduleTime = '09:00';
    }

    public function closeMissedAction(): void
    {
        $this->missedActionId = null;
        $this->rescheduleDate = '';
        $this->rescheduleTime = '09:00';
    }

    public function rescheduleMissed(): void
    {
        if (!$this->missedActionId) return;
        $this->validate([
            'rescheduleDate' => 'required|date|after_or_equal:today',
            'rescheduleTime' => 'required',
        ]);
        $newAt = Carbon::parse($this->rescheduleDate . ' ' . $this->rescheduleTime);
        $appointment = Appointments::findOrFail($this->missedActionId);
        $appointment->update([
            'scheduled_at' => $newAt,
            'status'       => 'Pending',
            'missed_at'    => null,
        ]);
        $this->tellPatient($appointment, 'rescheduled');
        $this->closeMissedAction();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Rescheduled to ' . $newAt->format('M d, Y h:i A') . '.']);
    }

    public function resolveMissed(int $id): void
    {
        Appointments::findOrFail($id)->update(['status' => 'Seen']);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Marked as resolved.']);
    }

    public function sendMissedFollowUpSms(int $id): void
    {
        $apt   = Appointments::with('patient')->findOrFail($id);
        $phone = trim($apt->patient->contact ?? '');
        if (!$phone) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'No contact number for this patient.']);
            return;
        }
        $msg    = $this->missedFollowUpMessage($apt);
        $result = (new SmsService)->send($phone, $msg, $apt->patient->id, 'appointment_missed_followup');
        if ($result['success']) {
            // The automatic follow-up (FollowUpSms) won't text this patient again.
            $apt->forceFill(['missed_followup_sent_at' => now()])->save();
            $this->dispatch('notify', ...['type' => 'success', 'message' => 'Follow-up SMS sent.']);
        } else {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'SMS failed: ' . ($result['error'] ?? 'Unknown error')]);
        }
    }

    public function updatedMissedView(): void
    {
        $this->resetPage();
        $this->resetSelection();
        $this->bulkFollowUpPlan = null;
    }

    /** Step 1: check the ticked missed appointments and show who will be texted before anything is sent. */
    public function prepareBulkFollowUp(): void
    {
        $this->bulkFollowUpPlan = $this->planBulkFollowUp();
        if ($this->bulkFollowUpPlan['send'] === [] && $this->bulkFollowUpPlan['skipped'] === []) {
            $this->bulkFollowUpPlan = null;
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Tick the missed appointments to follow up first.']);
        }
    }

    public function cancelBulkFollowUp(): void
    {
        $this->bulkFollowUpPlan = null;
    }

    /** Step 2: send to everyone the plan cleared (worked out again, in case anything changed). */
    public function sendBulkFollowUp(): void
    {
        $plan = $this->planBulkFollowUp();
        $this->bulkFollowUpPlan = null;
        if (! $plan['available']) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => $plan['reason']]);
            return;
        }

        $sms = new SmsService();
        $sent = $failed = 0;
        $appointments = Appointments::with(['patient', 'branch'])->whereIn('id', array_column($plan['send'], 'id'))->get()->keyBy('id');
        foreach ($plan['send'] as $row) {
            $appointment = $appointments[$row['id']] ?? null;
            if (! $appointment) continue;
            $result = $sms->send($appointment->patient->contact, $this->missedFollowUpMessage($appointment), $appointment->patient_id, 'appointment_missed_followup');
            if ($result['success'] ?? false) {
                // The automatic follow-up (FollowUpSms) won't text these patients again.
                $appointment->forceFill(['missed_followup_sent_at' => now()])->save();
                $sent++;
            } else {
                $failed++;
            }
        }

        \App\Models\AuditTrail::record('appointments.bulk_followup', "Missed-appointment follow-up SMS: {$sent} sent, {$failed} failed, ".count($plan['skipped']).' skipped');
        $this->resetSelection();
        $skipped = count($plan['skipped']) + $plan['leftOver'];
        $this->dispatch('notify', ...[
            'type' => $failed === 0 ? 'success' : 'warning',
            'message' => "Follow-up SMS sent: {$sent}" . ($skipped ? " · skipped: {$skipped}" : '') . ($failed ? " · failed: {$failed}" : '') . '.',
        ]);
    }

    /**
     * Who of the ticked appointments gets the follow-up. Skipped, with the reason: not a
     * missed appointment, no phone, opted out of SMS, reminders set to "none", already followed
     * up, or the same patient ticked twice. At most BULK_FOLLOW_UP_LIMIT go per click, and no
     * more than the clinic's credits cover.
     */
    private function planBulkFollowUp(): array
    {
        $availability = SmsAvailability::check();
        $appointments = Appointments::with(['patient', 'branch'])
            ->whereIn('id', array_map('intval', $this->selectedAppointments))->orderBy('scheduled_at')->get();

        $send = $skipped = $seenPatients = [];
        $credits = 0;
        foreach ($appointments as $appointment) {
            $patient = $appointment->patient;
            $reason = match (true) {
                $appointment->status !== 'Missed' => 'not a missed appointment',
                ! $patient || trim((string) $patient->contact) === '' => 'no phone number',
                (bool) $patient->sms_opt_out => 'opted out of SMS',
                $appointment->reminder_channel === 'none' => 'asked for no messages',
                $appointment->missed_followup_sent_at !== null => 'already followed up on ' . $appointment->missed_followup_sent_at->format('M d'),
                isset($seenPatients[$patient->id]) => 'same patient ticked twice',
                default => null,
            };
            if ($reason) {
                $skipped[] = ['name' => $patient?->name ?? 'Unknown patient', 'reason' => $reason];
                continue;
            }

            $seenPatients[$patient->id] = true;
            $parts = SmsSegments::count($this->missedFollowUpMessage($appointment));
            $send[] = ['id' => $appointment->id, 'name' => $patient->name, 'parts' => $parts];
            $credits += $parts;
        }

        // Keep within one click's limit and, on the platform gateway, within the credits left.
        $leftOver = 0;
        if (count($send) > self::BULK_FOLLOW_UP_LIMIT) {
            $leftOver = count($send) - self::BULK_FOLLOW_UP_LIMIT;
            $send = array_slice($send, 0, self::BULK_FOLLOW_UP_LIMIT);
        }
        if ($availability['credits'] !== null) {
            $affordable = [];
            $running = 0;
            foreach ($send as $row) {
                if ($running + $row['parts'] > $availability['credits']) break;
                $running += $row['parts'];
                $affordable[] = $row;
            }
            $leftOver += count($send) - count($affordable);
            $send = $affordable;
        }

        return [
            'send' => $send,
            'skipped' => $skipped,
            'leftOver' => $leftOver,
            'credits' => array_sum(array_column($send, 'parts')),
            'creditsLeft' => $availability['credits'],
            'available' => $availability['available'],
            'reason' => $availability['reason'],
            'preview' => ($first = $appointments->firstWhere('id', $send[0]['id'] ?? null)) ? $this->missedFollowUpMessage($first) : null,
        ];
    }

    public function render()
    {
        $this->markPastAppointmentsAsMissed();

        $showsCalendar = $this->activeFilter === 'schedule' && $this->scheduleView === 'calendar';

        $appointments = (!in_array($this->activeFilter, ['settings'], true) && !$showsCalendar)
            ? $this->getFilteredQuery()->orderBy('scheduled_at', 'asc')->paginate(10)
            : collect();

        return view('livewire.secretary.appointments-component', [
            'appointments' => $appointments,
            'calendarWeeks' => $this->calendarWeeks,
            'calendarAppointments' => $this->calendarAppointments,
            'dayTimeSlots' => $this->dayTimeSlots,
            'dayAppointments' => $this->dayAppointments,
            'statusSummary' => $this->statusSummary,
            'noShowStats' => !in_array($this->activeFilter, ['settings'], true) && !$showsCalendar ? $this->noShowStatsFor($appointments->getCollection()) : [],
            'searchablePatients' => (strlen($this->patientSearch) >= 2)
                ? Patient::quickSearch($this->patientSearch)->take(7)->get()
                : [],
            'doctors' => User::role('Doctor')->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.secretary.secretary-layout');
    }
}
