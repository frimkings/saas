<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OnlineBooking;
use App\Models\Patient;
use App\Models\Appointments;
use App\Models\Branch;
use App\Services\Messaging\AppointmentNotifier;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicBookingController extends Controller
{
    /**
     * Store an online booking request from the Vision Space website into `online_bookings`.
     * Does NOT pollute official clinical Patient registry until confirmed by clinic staff.
     */
    public function store(Request $request): JsonResponse
    {
        $bookingKey = (string) ($request->header('X-Clinic-Booking-Key') ?: $request->input('booking_key'));
        $branch = Branch::query()->where('public_booking_key', $bookingKey)->where('is_active', true)->first();
        abort_unless($branch && $bookingKey !== '', 404);
        $clinic = $branch->clinic()->where('status', 'active')->firstOrFail();
        $systemUser = $branch->users()->wherePivot('status', 'active')->orderBy('users.id')->first();
        abort_unless($systemUser, 503, 'This clinic is not accepting online bookings.');
        app(TenantContext::class)->set($systemUser, $clinic, $branch, [$branch->id]);
        if (app(\App\Services\ClinicAccessService::class)->stage()?->blocksStaff()) {
            return response()->json(['success' => false, 'message' => 'This clinic is not accepting online bookings at the moment. Please call the clinic directly.'], 503);
        }
        app(\App\Services\ClinicAccessService::class)->assertWritable('appointments');

        $validated = $request->validate([
            'booking_key' => 'nullable|string|max:64',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:255',
            'service' => 'nullable|string|max:255',
            'preferred_date' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $name = trim($validated['name']);
            $phone = trim($validated['phone']);
            $email = $validated['email'] ?? null;
            $service = $validated['service'] ?? 'Comprehensive Eye Examination';
            $rawDate = $validated['preferred_date'] ?? null;
            $notes = $validated['notes'] ?? '';

            // Parse preferred date
            $preferredDate = now()->addDay();
            if (! empty($rawDate)) {
                try {
                    $preferredDate = Carbon::parse($rawDate);
                } catch (\Throwable $e) {
                    $preferredDate = now()->addDay();
                }
            }

            // Create record in online_bookings table (Separate from Patients)
            $booking = OnlineBooking::create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'service' => $service,
                'preferred_date' => $preferredDate,
                'notes' => $notes,
                'status' => 'pending',
            ]);

            app(AppointmentNotifier::class)->onlineBookingReceived($booking);

            return response()->json([
                'success' => true,
                'message' => 'Online booking request received successfully!',
                'data' => [
                    'booking_id' => $booking->id,
                    'uuid' => $booking->uuid,
                    'name' => $booking->name,
                    'phone' => $booking->phone,
                    'service' => $booking->service,
                    'preferred_date' => $booking->preferred_date->toDateTimeString(),
                    'status' => $booking->status,
                ],
            ], 201);

        } catch (\Throwable $th) {
            Log::error('PublicBookingController Error: ' . $th->getMessage(), [
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process online booking at this time.',
            ], 500);
        }
    }

    /**
     * Convert an OnlineBooking request into an official Patient & Appointment.
     * Call this when clinic receptionist confirms or checks in the online booker.
     */
    public function convert(Request $request, string $booking): JsonResponse
    {
        $booking = OnlineBooking::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('id', $booking)->orWhere('uuid', $booking))
            ->firstOrFail();
        $branch = Branch::findOrFail($booking->branch_id);
        $clinic = $branch->clinic()->where('status', 'active')->firstOrFail();
        $authorized = $request->user()->branches()->whereKey($branch->id)
            ->wherePivot('status', 'active')->exists();
        abort_unless($authorized, 403);
        $branchIds = $request->user()->branches()->where('branches.clinic_id', $clinic->id)
            ->wherePivot('status', 'active')->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        app(TenantContext::class)->set($request->user(), $clinic, $branch, $branchIds);
        app(\App\Services\ClinicAccessService::class)->assertWritable('appointments');

        try {
            if ($booking->status === 'converted') {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking request has already been converted to a patient.',
                ], 422);
            }

            $systemUserId = $request->user()->id;

            // 1. Find existing patient or create official patient with generated PX number
            $patient = Patient::where('contact', $booking->phone)->first();

            if (! $patient) {
                $patient = Patient::createWithGeneratedPxNumber([
                    'user_id' => $systemUserId,
                    'name' => $booking->name,
                    'contact' => $booking->phone,
                    'email' => $booking->email,
                    'dob' => '2000-01-01',
                    'gender' => 'Other',
                    'address' => 'KNUST / Ayeduase Area',
                    'notes' => 'Converted from Website Online Booking #' . $booking->id,
                ]);
            }

            // 2. Create official Appointment record
            $appointment = Appointments::create([
                'patient_id' => $patient->id,
                'user_id' => $systemUserId,
                'title' => 'Online Booking - ' . $booking->service,
                'recall_category' => 'Online Consultation',
                'scheduled_at' => $booking->preferred_date ?? now(),
                'duration_minutes' => 30,
                'notes' => "Service: {$booking->service}\nNotes: {$booking->notes}",
                'status' => 'Booked',
                'reminder_channel' => 'whatsapp',
                'reminder_status' => 'pending',
            ]);

            // 3. Mark booking as converted
            $booking->status = 'converted';
            $booking->converted_patient_id = $patient->id;
            $booking->converted_appointment_id = $appointment->id;
            $booking->save();

            app(AppointmentNotifier::class)->confirmed($appointment);

            return response()->json([
                'success' => true,
                'message' => 'Successfully converted online booking to official Patient and Appointment!',
                'data' => [
                    'patient_id' => $patient->id,
                    'pxnumber' => $patient->pxnumber,
                    'appointment_id' => $appointment->id,
                ],
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to convert online booking.',
            ], 500);
        }
    }
}
