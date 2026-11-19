<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Consultations;
use App\Models\Refractions;
use App\Models\CashierPatientClearance;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class PatientMedicalRecordController extends Controller
{
    /**
     * Generate PDF for patient's complete medical record
     */
    public function generatePDF(Patient $patient, CashierPatientClearance $clearance)
    {
        abort_if(
            !\App\Support\Access\Access::can('clinical.records'),
            403
        );

        $this->ensureClearanceBelongsToPatient($patient, $clearance);

        // Get all consultations for this patient
        $consultations = Consultations::where('patient_id', $patient->id)
            ->with(['user', 'doctor', 'addenda.user', 'diagnoses', 'cartItems.product', 'refraction'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Get all refractions
        // Refractions belong to the patient through their consultations (the table has no patient_id).
        $refractions = Refractions::whereIn('consultation_id', $consultations->pluck('id'))
            ->with('consultation')
            ->orderBy('created_at', 'desc')
            ->get();

        // Prepare data
        $data = [
            'patient' => $patient,
            'clearance' => $clearance,
            'consultations' => $consultations,
            'refractions' => $refractions,
            'generatedAt' => now(),
            'generatedBy' => auth()->user(),
            'clinicSettings' => Setting::getSettings(),
        ];

        // Generate PDF
        $pdf = Pdf::loadView('doctor.medical-record-pdf', $data);
        
        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');
        
        // Return PDF for download
        return $this->withPageNumbers($pdf)->download('Medical_Record_' . $patient->pxnumber . '_' . now()->format('Ymd') . '.pdf');
    }

    /**
     * Generate PDF for single consultation
     */
    public function generateConsultationPDF(Consultations $consultation)
    {
        $patient = $consultation->patient;
        $consultation->load(['user', 'doctor', 'addenda.user', 'diagnoses', 'cartItems.product', 'refraction']);
        
        // Get refraction if exists
        $refraction = Refractions::where('consultation_id', $consultation->id)->first();

        $data = [
            'patient' => $patient,
            'consultation' => $consultation,
            'refraction' => $refraction,
            'generatedAt' => now(),
            'generatedBy' => auth()->user(),
            'clinicSettings' => Setting::getSettings(),
        ];

        $pdf = Pdf::loadView('doctor.consultation-pdf', $data);
        $pdf->setPaper('A4', 'portrait');
        
        return $this->withPageNumbers($pdf)->download('Consultation_' . $patient->pxnumber . '_' . $consultation->created_at->format('Ymd') . '.pdf');
    }

    public function visitSummary(Consultations $consultation)
    {
        $consultation->load(['patient', 'doctor', 'diagnoses', 'cartItems.product', 'refraction', 'documents', 'addenda.user']);

        return view('doctor.visit-summary-print', [
            'consultation' => $consultation,
            'patient' => $consultation->patient,
            'generatedAt' => now(),
            'generatedBy' => auth()->user(),
            'clinicSettings' => Setting::getSettings(),
        ]);
    }

    public function downloadVisitSummaries(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404, 'No visit summaries selected.');

        $query = Consultations::with(['patient', 'doctor', 'diagnoses', 'cartItems.product', 'refraction', 'documents', 'addenda.user'])
            ->whereIn('id', $ids);

        $consultations = $query->orderBy('created_at', 'desc')->get();

        abort_if($consultations->isEmpty(), 404, 'Selected visit summaries were not found.');
        abort_if($consultations->count() < $ids->count(), 403, 'One or more summaries are not accessible.');
        abort_if($consultations->pluck('patient_id')->unique()->count() !== 1, 403, 'Visit summaries must belong to one patient.');

        $patient = $consultations->first()->patient;
        $pdf = Pdf::loadView('doctor.visit-summaries-pdf', [
            'consultations' => $consultations,
            'patient' => $patient,
            'generatedAt' => now(),
            'generatedBy' => auth()->user(),
            'clinicSettings' => Setting::getSettings(),
        ])->setPaper('A4', 'portrait');

        $filename = 'Clinical_Visit_Summaries_' . ($patient->pxnumber ?? 'patient') . '_' . now()->format('Ymd_His') . '.pdf';

        return $this->withPageNumbers($pdf)->download($filename);
    }

    public function printPrescription(Consultations $consultation)
    {
        $consultation->load(['patient', 'doctor', 'user']);

        // Only the drugs to buy at a pharmacy: what the clinic dispensed is not on the slip.
        $outsideItems = $consultation->outsidePrescriptions()->withoutGlobalScope('branch')->orderBy('id')->get();

        $settings = Setting::getSettings();
        $patientName = Str::slug($consultation->patient->name ?? 'patient');
        $filename = 'Drug_Prescription_' . ($patientName ?: 'patient') . '_' . $consultation->created_at->format('Ymd') . '.pdf';

        $paper = \App\Support\Clinical\Slip::paper($settings);
        $pdf = Pdf::loadView('pdf.prescription-slip', [
            'consultation' => $consultation,
            'patient'      => $consultation->patient,
            'doctor'       => $consultation->doctor,
            'outsideItems' => $outsideItems,
            'settings'     => $settings,
            'paper'        => $paper,
        ])->setPaper(...\App\Support\Clinical\Slip::dompdfPaper($paper));

        return $pdf->stream($filename);
    }

    /** The spectacle prescription on A5 (or two copies on A4), for the patient to take to an optical lab. */
    public function printRefraction(Consultations $consultation)
    {
        abort_unless(\App\Support\Access\Access::can('clinical.print'), 403);
        $consultation->load(['patient', 'doctor', 'user']);
        $refraction = Refractions::where('consultation_id', $consultation->id)->first();
        abort_unless($refraction, 404, 'No refraction was recorded for this consultation.');

        $settings = Setting::getSettings();
        $paper = \App\Support\Clinical\Slip::paper($settings);
        $filename = 'Spectacle_Prescription_' . (Str::slug($consultation->patient->name ?? '') ?: 'patient') . '_' . $consultation->created_at->format('Ymd') . '.pdf';

        return Pdf::loadView('pdf.refraction-slip', [
            'consultation' => $consultation,
            'refraction'   => $refraction,
            'patient'      => $consultation->patient,
            'settings'     => $settings,
            'paper'        => $paper,
        ])->setPaper(...\App\Support\Clinical\Slip::dompdfPaper($paper))->stream($filename);
    }

    /**
     * Preview medical record in browser
     */
    public function preview(Patient $patient, CashierPatientClearance $clearance)
    {
        $this->ensureClearanceBelongsToPatient($patient, $clearance);

        // Get all consultations for this patient
        $consultations = Consultations::where('patient_id', $patient->id)
            ->with(['user', 'doctor', 'addenda.user', 'diagnoses', 'cartItems.product', 'refraction'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Get all refractions
        // Refractions belong to the patient through their consultations (the table has no patient_id).
        $refractions = Refractions::whereIn('consultation_id', $consultations->pluck('id'))
            ->with('consultation')
            ->orderBy('created_at', 'desc')
            ->get();

        // Prepare data
        $data = [
            'patient' => $patient,
            'clearance' => $clearance,
            'consultations' => $consultations,
            'refractions' => $refractions,
            'generatedAt' => now(),
            'generatedBy' => auth()->user(),
            'isPreview' => true,
            'clinicSettings' => Setting::getSettings(),
        ];

        return view('doctor.medical-record-pdf', $data + ['screen' => true]);
    }

    private function ensureClearanceBelongsToPatient(
        Patient $patient,
        CashierPatientClearance $clearance
    ): void {
        abort_unless(
            (int) $clearance->patient_id === (int) $patient->getKey(),
            404
        );
    }

    /** Renders the PDF and stamps "Page x of y" in the footer of every page (dompdf has no total-pages counter in CSS). */
    private function withPageNumbers(\Barryvdh\DomPDF\PDF $pdf): \Barryvdh\DomPDF\PDF
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 34 - 50, $canvas->get_height() - 33, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.39, 0.45, 0.53]);

        return $pdf;
    }
}
