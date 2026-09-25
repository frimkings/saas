<?php

namespace Tests\Unit;

use App\Http\Controllers\Doctor\PatientMedicalRecordController;
use App\Models\CashierPatientClearance;
use App\Models\Patient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PatientMedicalRecordControllerTest extends TestCase
{
    public function test_preview_rejects_a_clearance_belonging_to_another_patient(): void
    {
        $patient = new Patient();
        $patient->setAttribute('id', 10);

        $clearance = new CashierPatientClearance();
        $clearance->setAttribute('patient_id', 20);

        $this->expectException(NotFoundHttpException::class);

        app(PatientMedicalRecordController::class)->preview($patient, $clearance);
    }
}
