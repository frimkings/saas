<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConsultationDemoSeeder extends Seeder
{
    private const TARGET = 250_000;
    private const CHUNK  = 500;

    private const COMPLAINTS = [
        'Blurred vision','Eye redness','Itchy eyes','Foreign body sensation',
        'Watery eyes','Headache with eye strain','Double vision','Floaters',
        'Eye pain','Discharge from eye','Sensitivity to light','Difficulty reading',
        'Night blindness','Loss of peripheral vision','Eye fatigue after screen use',
        'Dry eyes','Burning sensation','Swollen eyelids','Flashes of light',
        'Sudden vision loss','Eye injury','Blurred near vision','Cloudy vision',
        'Reduced colour vision','Squinting',
    ];
    private const VA_OPTIONS = ['6/6','6/9','6/12','6/18','6/24','6/36','6/60','CF','HM','PL'];
    private const FINDINGS   = ['Normal','Mild congestion','Mild haziness','Clear','Reactive','Normal size','Slightly dilated','Irregular'];
    private const CDR_VALUES = ['0.2','0.3','0.3','0.4','0.4','0.4','0.5','0.5','0.6','0.7','0.8'];
    private const LENS_TYPES = ['Single Vision','Progressive','Bifocal','Anti-Reflective','Photochromic'];

    public function run(): void
    {
        $existing = DB::table('consultations')->count();
        $toSeed   = self::TARGET - $existing;

        if ($toSeed <= 0) {
            $this->command->info('ConsultationDemoSeeder: already at target (' . number_format($existing) . ' consultations).');
            return;
        }

        // Load clearances that do not yet have a consultation
        $this->command->info("Loading available clearances...");
        $clearances = DB::table('cashier_patient_clearances as c')
            ->leftJoin('consultations as co', 'co.clearance_id', '=', 'c.id')
            ->whereNull('co.id')
            ->select('c.id', 'c.patient_id', 'c.clearance_date', 'c.user_id')
            ->limit($toSeed)
            ->get()
            ->toArray();

        if (empty($clearances)) {
            $this->command->error('No available clearances. Run ClearanceDemoSeeder first (needs more clearances than consultations).');
            return;
        }

        $available    = count($clearances);
        $diagnosisIds = DB::table('diagnoses')->pluck('id')->toArray();
        $userIds      = DB::table('users')->pluck('id')->toArray();

        if (empty($diagnosisIds)) {
            $this->command->error('No diagnoses found. Run DiagnosisSeeder first.');
            return;
        }

        $this->command->info("Seeding {$available} consultations (target: " . number_format(self::TARGET) . ')...');
        $bar = $this->command->getOutput()->createProgressBar($available);
        $bar->start();

        $diagCount = count($diagnosisIds);
        $processed = 0;

        foreach (array_chunk($clearances, self::CHUNK) as $chunk) {
            $consultRows   = [];
            $clearanceIds  = [];

            foreach ($chunk as $clearance) {
                $iopOD = rand(1, 10) === 1 ? rand(22, 35) : rand(10, 21); // 10% glaucoma suspect
                $iopOS = rand(1, 10) === 1 ? rand(22, 35) : rand(10, 21);
                $date  = $clearance->clearance_date . ' ' . str_pad(rand(8, 16), 2, '0', STR_PAD_LEFT) . ':' . str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00';

                $consultRows[]  = [
                    'patient_id'     => $clearance->patient_id,
                    'user_id'        => $userIds[array_rand($userIds)],
                    'clearance_id'   => $clearance->id,
                    'chiefComplaint' => self::COMPLAINTS[array_rand(self::COMPLAINTS)],
                    'vaOD6m'         => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                    'vaOS6m'         => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                    'lidsOD'         => self::FINDINGS[array_rand(self::FINDINGS)],
                    'lidsOS'         => self::FINDINGS[array_rand(self::FINDINGS)],
                    'conjunctivaOD'  => self::FINDINGS[array_rand(self::FINDINGS)],
                    'conjunctivaOS'  => self::FINDINGS[array_rand(self::FINDINGS)],
                    'corneaOD'       => self::FINDINGS[array_rand(self::FINDINGS)],
                    'corneaOS'       => self::FINDINGS[array_rand(self::FINDINGS)],
                    'irisOD'         => self::FINDINGS[array_rand(self::FINDINGS)],
                    'irisOS'         => self::FINDINGS[array_rand(self::FINDINGS)],
                    'pupilOD'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'pupilOS'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'lensOD'         => self::FINDINGS[array_rand(self::FINDINGS)],
                    'lensOS'         => self::FINDINGS[array_rand(self::FINDINGS)],
                    'vitreousOD'     => self::FINDINGS[array_rand(self::FINDINGS)],
                    'vitreousOS'     => self::FINDINGS[array_rand(self::FINDINGS)],
                    'fundusOD'       => self::FINDINGS[array_rand(self::FINDINGS)],
                    'fundusOS'       => self::FINDINGS[array_rand(self::FINDINGS)],
                    'cdrOD'          => self::CDR_VALUES[array_rand(self::CDR_VALUES)],
                    'cdrOS'          => self::CDR_VALUES[array_rand(self::CDR_VALUES)],
                    'IOPOD'          => $iopOD,
                    'IOPOS'          => $iopOS,
                    'notes'          => null,
                    'review'         => null,
                    'created_at'     => $date,
                    'updated_at'     => $date,
                ];
                $clearanceIds[] = $clearance->id;
            }

            DB::table('consultations')->insert($consultRows);

            // Fetch the IDs of the newly inserted consultations
            $consultMap = DB::table('consultations')
                ->whereIn('clearance_id', $clearanceIds)
                ->pluck('id', 'clearance_id')
                ->toArray();

            // Seed consultation_diagnosis pivot (1–3 diagnoses per consultation)
            $pivotRows = [];
            $refRows   = [];
            $now       = now()->format('Y-m-d H:i:s');

            foreach ($consultRows as $cr) {
                $consultId = $consultMap[$cr['clearance_id']] ?? null;
                if (!$consultId) continue;

                $numDiag  = rand(1, 3);
                $diagKeys = (array) array_rand($diagnosisIds, min($numDiag, $diagCount));
                foreach ($diagKeys as $key) {
                    $pivotRows[] = [
                        'consultation_id' => $consultId,
                        'diagnosis_id'    => $diagnosisIds[$key],
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];
                }

                // 30% of consultations get a refraction record
                if (rand(1, 10) <= 3) {
                    $sph  = sprintf('%+.2f', rand(-1500, 500) / 100);
                    $cyl  = sprintf('%.2f', -(rand(0, 250) / 100));
                    $axis = rand(1, 180);
                    $sphOS  = sprintf('%+.2f', rand(-1500, 500) / 100);
                    $cylOS  = sprintf('%.2f', -(rand(0, 250) / 100));
                    $axisOS = rand(1, 180);

                    $refRows[] = [
                        'user_id'                  => $cr['user_id'],
                        'consultation_id'          => $consultId,
                        'refractionOD'             => "{$sph}/{$cyl}×{$axis}",
                        'refractionOS'             => "{$sphOS}/{$cylOS}×{$axisOS}",
                        'refractionOD_distance_va' => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                        'refractionOS_distance_va' => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                        'refractionOD_ADD'         => rand(0, 1) ? sprintf('+%.2f', rand(75, 300) / 100) : null,
                        'refractionOS_ADD'         => rand(0, 1) ? sprintf('+%.2f', rand(75, 300) / 100) : null,
                        'refractionOD_near_va'     => null,
                        'refractionOS_near_va'     => null,
                        'lensType'                 => self::LENS_TYPES[array_rand(self::LENS_TYPES)],
                        'pd'                       => rand(58, 68),
                        'notes'                    => null,
                        'refractionnotes'          => null,
                        'created_at'               => $cr['created_at'],
                        'updated_at'               => $cr['updated_at'],
                    ];
                }
            }

            if ($pivotRows) {
                DB::table('consultation_diagnosis')->insert($pivotRows);
            }
            if ($refRows) {
                DB::table('refractions')->insertOrIgnore($refRows);
            }

            $processed += count($chunk);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('ConsultationDemoSeeder done — ' . number_format(DB::table('consultations')->count()) . ' consultations, '
            . number_format(DB::table('refractions')->count()) . ' refractions.');
    }
}
