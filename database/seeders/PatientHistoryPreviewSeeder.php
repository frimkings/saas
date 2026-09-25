<?php

namespace Database\Seeders;

use App\Models\{Branch, CashierPatientClearance, Clinic, Consultations, Refractions, User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PatientHistoryPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) {
            throw new \RuntimeException('Preview data is restricted to the local environment.');
        }
        $source = CashierPatientClearance::withoutGlobalScopes()
            ->where('uuid', '61dce9e6-ee9d-4a8b-96c7-ea40dc0d7f1e')->firstOrFail();
        $original = Consultations::withoutGlobalScopes()->where('clearance_id', $source->id)->firstOrFail();
        $context = app(TenantContext::class);
        $context->set(User::findOrFail($original->user_id), Clinic::findOrFail($source->clinic_id),
            Branch::withoutGlobalScopes()->findOrFail($source->branch_id), [$source->branch_id]);
        $marker = '[DEMO HISTORY 2026-09-15]';
        $created = [];
        try {
            DB::transaction(function () use ($source, $original, $marker, &$created) {
                $source->newQuery()->whereKey($source->id)->lockForUpdate()->firstOrFail();
                if (Consultations::where('patient_id', $source->patient_id)->where('chiefComplaint', 'like', $marker.'%')->exists()) {
                    throw new \RuntimeException('This preview batch already exists; no duplicate records were added.');
                }
                $complaints = ['Blurred distance vision', 'Eye strain during reading', 'Dry eye symptoms', 'Routine vision review', 'Glasses prescription review'];
                $acuities = ['6/9', '6/12', '6/18', '6/24', '6/36'];
                $diagnosisIds = $original->diagnoses()->pluck('diagnoses.id')->all();
                for ($i = 1; $i <= 40; $i++) {
                    $date = now()->startOfDay()->subDays(41 - $i)->setTime(9 + ($i % 7), ($i * 7) % 60);
                    $clearance = $source->replicate();
                    $clearance->uuid = (string) Str::uuid();
                    $clearance->clearance_date = $date->toDateString();
                    $clearance->sale_id = null;
                    $clearance->payment_status = 'Unpaid';
                    $clearance->doctor_status = true;
                    $clearance->created_at = $clearance->updated_at = $date;
                    $clearance->saveQuietly();
                    $visit = new Consultations([
                        'patient_id' => $source->patient_id, 'user_id' => $original->user_id,
                        'clearance_id' => $clearance->id,
                        'chiefComplaint' => $marker.' '.str_pad($i, 2, '0', STR_PAD_LEFT).' — '.$complaints[($i - 1) % 5],
                        'others' => 'Synthetic preview data only; not a clinical assessment.',
                        'notes' => $marker.' Generated to preview charts, pagination and refraction history. Not for clinical use.',
                        'odq' => $i % 3 === 0 ? ['Dry Eyes', 'Blurred Vision'] : ['Blurred Vision'],
                        'vaOD6m' => $acuities[(int) floor(($i - 1) / 8)],
                        'vaOS6m' => $acuities[min(4, (int) floor(($i + 2) / 9))],
                        'IOPOD' => round(15 + 3 * sin($i / 4) + ($i % 13 === 0 ? 6 : 0), 1),
                        'IOPOS' => round(16 + 2.5 * sin($i / 4 + .5), 1),
                        'cdrOD' => '0.3', 'cdrOS' => '0.3',
                    ]);
                    foreach (['OD', 'OS'] as $eye) {
                        foreach (['lids' => 'Normal', 'conjunctiva' => 'White and quiet', 'cornea' => 'Clear', 'iris' => 'Normal pattern', 'pupil' => 'Round and reactive', 'ac' => 'Deep and quiet', 'lens' => 'Clear', 'vitreous' => 'Clear', 'fundus' => 'Normal'] as $field => $value) {
                            $visit->{$field.$eye} = $value;
                        }
                    }
                    $visit->created_at = $visit->updated_at = $date;
                    $visit->save();
                    $visit->diagnoses()->sync($diagnosisIds);
                    $rx = new Refractions(['consultation_id' => $visit->id, 'user_id' => $original->user_id,
                        'objective_method' => $i % 2 ? 'retinoscopy' : 'auto_refraction',
                        'pd' => 62, 'lensType' => ['SV BLUE BLOCK', 'SV PHOTO AR', 'Single Vision'][($i - 1) % 3],
                        'dispensing_required' => false, 'refractionnotes' => $marker.' Synthetic prescription for preview only.']);
                    foreach (['od', 'os'] as $eye) {
                        $sphere = -1 - .25 * (int) floor($i / 5) - ($eye === 'os' ? .5 : 0);
                        $cylinder = -.5 - .25 * ($i % 4);
                        $axis = $eye === 'od' ? 110 + ($i % 7) * 5 : 85 + ($i % 8) * 5;
                        foreach (['objective', 'subjective'] as $phase) {
                            $rx->{$phase.'_'.$eye.'_sphere'} = $sphere;
                            $rx->{$phase.'_'.$eye.'_cylinder'} = $cylinder;
                            $rx->{$phase.'_'.$eye.'_axis'} = $axis;
                        }
                        $rx->{'objective_'.$eye.'_va'} = '6/9';
                        $rx->{'subjective_'.$eye.'_bcva'} = '6/6';
                        $rx->{'refraction'.strtoupper($eye)} = Refractions::formatPrescription($sphere, $cylinder, $axis);
                        $rx->{'refraction'.strtoupper($eye).'_distance_va'} = '6/6';
                    }
                    $rx->created_at = $rx->updated_at = $date;
                    $rx->save();
                    $created[] = $visit->id;
                }
            });
            $this->command?->info('Added '.count($created).' demo visits with refractions for patient '.$source->patient_id.'. Visit IDs: '.implode(', ', $created));
            $this->command?->info('Total visits: '.Consultations::where('patient_id', $source->patient_id)->count());
        } finally {
            $context->clear();
        }
    }
}
