<?php

namespace App\Livewire\Doctor;

use App\Models\Appointments;
use App\Models\Consultations;
use App\Models\LensOrder;
use App\Models\Patient;
use App\Models\Referral;
use App\Models\Refractions;
use Livewire\Component;

class PatientTimelineComponent extends Component
{
    public Patient $patient;

    public $filterType = 'all';
    public $expandedId = null;

    public function mount(Patient $patient)
    {
        $this->patient = $patient;
    }

    public function toggleExpand($key)
    {
        $this->expandedId = $this->expandedId === $key ? null : $key;
    }

    public function getTimelineProperty(): \Illuminate\Support\Collection
    {
        $events = collect();

        // Consultations
        Consultations::where('patient_id', $this->patient->id)
            ->with(['doctor', 'diagnoses', 'cartItems.product'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->each(function ($c) use (&$events) {
                $events->push([
                    'type'    => 'consultation',
                    'date'    => $c->created_at,
                    'key'     => 'consultation_' . $c->id,
                    'data'    => $c,
                ]);
            });

        // Refractions (+ nested lens orders) — linked via consultation
        $consultationIds = Consultations::where('patient_id', $this->patient->id)
            ->pluck('id');

        Refractions::whereIn('consultation_id', $consultationIds)
            ->with(['user', 'lensOrder'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->each(function ($r) use (&$events) {
                $events->push([
                    'type' => 'refraction',
                    'date' => $r->created_at,
                    'key'  => 'refraction_' . $r->id,
                    'data' => $r,
                ]);

                if ($r->lensOrder) {
                    $events->push([
                        'type' => 'lens_order',
                        'date' => $r->lensOrder->created_at,
                        'key'  => 'lens_' . $r->lensOrder->id,
                        'data' => $r->lensOrder,
                    ]);
                }
            });

        // Referrals
        Referral::where('patient_id', $this->patient->id)
            ->with('referredBy')
            ->orderBy('referral_date', 'desc')
            ->get()
            ->each(function ($ref) use (&$events) {
                $events->push([
                    'type' => 'referral',
                    'date' => $ref->referral_date,
                    'key'  => 'referral_' . $ref->id,
                    'data' => $ref,
                ]);
            });

        // Appointments
        Appointments::where('patient_id', $this->patient->id)
            ->orderBy('scheduled_at', 'desc')
            ->get()
            ->each(function ($apt) use (&$events) {
                $events->push([
                    'type' => 'appointment',
                    'date' => $apt->scheduled_at,
                    'key'  => 'appointment_' . $apt->id,
                    'data' => $apt,
                ]);
            });

        $filtered = $this->filterType === 'all'
            ? $events
            : $events->where('type', $this->filterType);

        return $filtered->sortByDesc('date')->values();
    }

    public function render()
    {
        return view('livewire.doctor.patient-timeline-component', [
            'timeline' => $this->timeline,
        ])->layout('layouts.doctor.doctor-layout');
    }
}
