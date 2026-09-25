<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DummyConsultationsSeeder extends Seeder
{
    public function run(): void
    {
        $patientIds = DB::table('patients')->pluck('id')->toArray();
        $doctorIds  = [836, 857, 859];

        $chiefComplaints = [
            'Blurred vision', 'Eye pain', 'Redness in eye', 'Itching and discharge',
            'Double vision', 'Floaters', 'Photophobia', 'Dry eyes', 'Foreign body sensation',
            'Watering eyes', 'Reduced vision', 'Headache with eye strain', 'Night blindness',
            'Sudden loss of vision', 'Peripheral vision loss', 'Eye fatigue',
        ];

        $vaValues = ['6/4','6/5','6/6','6/9','6/12','6/18','6/24','6/36','6/60','3/60','1/60','CF','HM','PL','NPL'];

        $ocularFindings = [
            'Normal', 'Mild injection', 'Moderate injection', 'Papillary reaction',
            'Follicular reaction', 'Pterygium', 'Pinguecula', 'Subconjunctival haemorrhage',
        ];

        $corneaFindings = [
            'Clear', 'Mild haze', 'Scarring', 'Keratoconus', 'Arcus senilis',
            'Superficial punctate keratitis', 'Normal',
        ];

        $lensFindings = [
            'Clear', 'Nuclear sclerosis grade I', 'Nuclear sclerosis grade II',
            'Nuclear sclerosis grade III', 'Posterior subcapsular cataract',
            'Cortical cataract', 'Pseudophakia', 'Aphakia',
        ];

        $fundusFindings = [
            'Normal disc', 'Healthy macula', 'Cup:disc ratio 0.3', 'Cup:disc ratio 0.5',
            'Cup:disc ratio 0.7', 'Disc pallor', 'Diabetic retinopathy changes',
            'Hypertensive retinopathy', 'Age-related macular degeneration',
        ];

        $notesList = [
            'Review in 3 months.', 'Continue current medication.', 'Referred for further evaluation.',
            'Patient advised on hygiene.', 'Glasses prescription given.', 'Follow-up in 6 weeks.',
            'Monitor IOP closely.', 'Advised to avoid screen time.', 'Low vision aids discussed.',
            'Surgical consultation arranged.',
        ];

        // Load already-used (patient_id, clearance_date) pairs to avoid duplicates
        $used = DB::table('cashier_patient_clearances')
            ->get(['patient_id', 'clearance_date'])
            ->map(fn ($r) => $r->patient_id . '|' . $r->clearance_date)
            ->flip()
            ->toArray();

        $startDate = Carbon::now()->subYear();

        // Generate 500 unique (patient_id, date) pairs
        $pairs = [];
        $attempts = 0;
        while (count($pairs) < 500 && $attempts < 50000) {
            $attempts++;
            $patientId = $patientIds[array_rand($patientIds)];
            $date      = $startDate->copy()->addDays(rand(0, 364))->toDateString();
            $key       = $patientId . '|' . $date;
            if (!isset($used[$key])) {
                $used[$key] = true;
                $pairs[]    = ['patient_id' => $patientId, 'date' => $date];
            }
        }

        $this->command->info('Generated ' . count($pairs) . ' unique pairs.');

        // Build and insert clearance rows
        $clearanceRows = [];
        foreach ($pairs as $pair) {
            $doctorId = $doctorIds[array_rand($doctorIds)];
            $clearanceRows[] = [
                'user_id'        => $doctorId,
                'patient_id'     => $pair['patient_id'],
                'payment_status' => 1,
                'doctor_status'  => 1,
                'clearance_date' => $pair['date'],
                'created_at'     => $pair['date'] . ' 08:00:00',
                'updated_at'     => $pair['date'] . ' 08:00:00',
            ];
        }

        foreach (array_chunk($clearanceRows, 100) as $chunk) {
            DB::table('cashier_patient_clearances')->insert($chunk);
        }

        // Fetch the inserted clearance IDs (last N by id)
        $inserted = DB::table('cashier_patient_clearances')
            ->orderByDesc('id')
            ->limit(count($pairs))
            ->get(['id', 'patient_id', 'user_id', 'clearance_date'])
            ->sortBy('id')
            ->values();

        // Build and insert consultation rows
        $consultationRows = [];
        foreach ($inserted as $c) {
            $consultationRows[] = [
                'user_id'        => $c->user_id,
                'patient_id'     => $c->patient_id,
                'clearance_id'   => $c->id,
                'chiefComplaint' => $chiefComplaints[array_rand($chiefComplaints)],
                'vaOD6m'         => $vaValues[array_rand($vaValues)],
                'vaOS6m'         => $vaValues[array_rand($vaValues)],
                'lidsOD'         => $ocularFindings[array_rand($ocularFindings)],
                'lidsOS'         => $ocularFindings[array_rand($ocularFindings)],
                'conjunctivaOD'  => $ocularFindings[array_rand($ocularFindings)],
                'conjunctivaOS'  => $ocularFindings[array_rand($ocularFindings)],
                'corneaOD'       => $corneaFindings[array_rand($corneaFindings)],
                'corneaOS'       => $corneaFindings[array_rand($corneaFindings)],
                'lensOD'         => $lensFindings[array_rand($lensFindings)],
                'lensOS'         => $lensFindings[array_rand($lensFindings)],
                'fundusOD'       => $fundusFindings[array_rand($fundusFindings)],
                'fundusOS'       => $fundusFindings[array_rand($fundusFindings)],
                'cdrOD'          => (string) round(rand(1, 9) / 10, 1),
                'cdrOS'          => (string) round(rand(1, 9) / 10, 1),
                'IOPOD'          => round(rand(10, 30) + rand(0, 9) / 10, 1),
                'IOPOS'          => round(rand(10, 30) + rand(0, 9) / 10, 1),
                'notes'          => $notesList[array_rand($notesList)],
                'created_at'     => $c->clearance_date . ' 08:00:00',
                'updated_at'     => $c->clearance_date . ' 08:00:00',
            ];
        }

        foreach (array_chunk($consultationRows, 100) as $chunk) {
            DB::table('consultations')->insert($chunk);
        }

        $this->command->info('Done — inserted ' . count($consultationRows) . ' consultations.');
    }
}
