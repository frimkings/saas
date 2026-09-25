<?php

namespace Database\Seeders;

use App\Models\CashierPatientClearance;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConsultationSeeder extends Seeder
{
    private const BATCH = 20_000;
    private const CHUNK = 500;

    private const COMPLAINTS = [
        'Blurred vision', 'Eye redness', 'Itchy eyes', 'Foreign body sensation',
        'Watery eyes', 'Headache with eye strain', 'Double vision', 'Floaters',
        'Eye pain', 'Discharge from eye', 'Sensitivity to light', 'Difficulty reading',
        'Night blindness', 'Loss of peripheral vision', 'Eye fatigue after screen use',
        'Dry eyes', 'Burning sensation', 'Swollen eyelids', 'Flashes of light',
        'Sudden vision loss',
    ];

    private const VA_OPTIONS = ['6/6', '6/9', '6/12', '6/18', '6/24', '6/36', '6/60', 'CF', 'HM', 'PL'];

    private const FINDINGS = ['Normal', 'Mild congestion', 'Mild haziness', 'Clear', 'Reactive', 'Normal size'];

    public function run(): void
    {
        $available = CashierPatientClearance::doesntHave('consultation')
            ->limit(self::BATCH)
            ->get(['id', 'patient_id']);

        if ($available->isEmpty()) {
            $this->command->warn('No available clearances left — all have consultations already.');
            return;
        }

        $userIds      = DB::table('users')->pluck('id')->toArray();
        $diagnosisIds = DB::table('diagnoses')->pluck('id')->toArray();
        $start        = Carbon::create(2025, 1, 1)->timestamp;
        $end          = Carbon::now()->timestamp;
        $total        = $available->count();
        $now          = now()->format('Y-m-d H:i:s');

        $bar = $this->command->getOutput()->createProgressBar($total);
        $bar->start();

        foreach ($available->chunk(self::CHUNK) as $chunk) {
            $clearanceIds = $chunk->pluck('id')->toArray();
            $rows = [];

            foreach ($chunk as $clearance) {
                $date   = Carbon::createFromTimestamp(rand($start, $end))->format('Y-m-d H:i:s');
                $rows[] = [
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
                    'pupilOD'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'pupilOS'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'notes'          => null,
                    'created_at'     => $date,
                    'updated_at'     => $date,
                ];
            }

            DB::table('consultations')->insert($rows);

            // Fetch IDs of just-inserted consultations, then seed pivot
            $consultationIds = DB::table('consultations')
                ->whereIn('clearance_id', $clearanceIds)
                ->pluck('id')
                ->toArray();

            $pivotRows = [];
            foreach ($consultationIds as $consultationId) {
                // 1–3 unique diagnoses per consultation
                $count      = rand(1, 3);
                $keys       = array_rand($diagnosisIds, min($count, count($diagnosisIds)));
                $selected   = is_array($keys) ? $keys : [$keys];
                foreach ($selected as $key) {
                    $pivotRows[] = [
                        'consultation_id' => $consultationId,
                        'diagnosis_id'    => $diagnosisIds[$key],
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];
                }
            }

            DB::table('consultation_diagnosis')->insert($pivotRows);
            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Done — seeded {$total} consultations with diagnoses.");
        $this->command->line('Remaining clearances without consultation: '
            . CashierPatientClearance::doesntHave('consultation')->count());
    }
}
