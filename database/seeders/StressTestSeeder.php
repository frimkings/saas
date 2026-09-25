<?php

namespace Database\Seeders;

use App\Models\Appointments;
use App\Models\CashierPatientClearance;
use App\Models\Consultations;
use App\Models\Patient;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StressTestSeeder extends Seeder
{
    private const CHUNK = 500;

    public function run(): void
    {
        DB::disableQueryLog();
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $faker = Faker::create();

        // Pre-fetch existing user IDs
        $userIds = User::pluck('id')->toArray();
        if (empty($userIds)) {
            $this->command->error('No users found — run: php artisan db:seed');
            return;
        }

        // Phase 1: Patients (factory, override user_id only)
        $this->command->info('Seeding 20 000 patients...');
        $this->seedInChunks(20_000, function (int $batch) use ($userIds) {
            Patient::factory($batch)
                ->state(fn () => ['user_id' => $userIds[array_rand($userIds)]])
                ->create();
        });

        // Pre-fetch all patient IDs (including previously seeded ones)
        $patientIds = Patient::pluck('id')->toArray();

        // Phase 2: Clearances — raw insertOrIgnore to handle (patient_id, clearance_date) unique key
        $this->command->info('Seeding 50 000 clearances...');
        $this->seedRaw('cashier_patient_clearances', 50_000, function (int $batch) use ($faker, $userIds, $patientIds) {
            $rows = [];
            for ($i = 0; $i < $batch; $i++) {
                $rows[] = [
                    'user_id'        => $userIds[array_rand($userIds)],
                    'patient_id'     => $patientIds[array_rand($patientIds)],
                    'payment_status' => $faker->randomElement(['Paid', 'Unpaid']),
                    'doctor_status'  => rand(0, 1),
                    'clearance_date' => $faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }
            return $rows;
        });

        // Phase 3: Appointments — raw insertOrIgnore
        $this->command->info('Seeding 30 000 appointments...');
        $titles = ['Eye Exam', 'Follow-up', 'Contact Lens Fitting', 'Cataract Consultation', 'Glaucoma Check', 'Refraction Test'];
        $this->seedRaw('appointments', 30_000, function (int $batch) use ($faker, $userIds, $patientIds, $titles) {
            $rows = [];
            for ($i = 0; $i < $batch; $i++) {
                $rows[] = [
                    'user_id'      => $userIds[array_rand($userIds)],
                    'patient_id'   => $patientIds[array_rand($patientIds)],
                    'title'        => $titles[array_rand($titles)],
                    'notes'        => $faker->sentence(),
                    'scheduled_at' => $faker->dateTimeBetween('-1 year', '+60 days')->format('Y-m-d H:i:s'),
                    'status'       => $faker->randomElement(['scheduled', 'completed', 'cancelled']),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ];
            }
            return $rows;
        });

        // Phase 4: Consultations — one per clearance (unique clearance_id)
        $this->command->info('Seeding 20 000 consultations...');
        $clearanceIds = CashierPatientClearance::pluck('id', 'patient_id'); // id => patient_id map
        $clearancePairs = CashierPatientClearance::select('id', 'patient_id')->get()
            ->map(fn ($r) => ['clearance_id' => $r->id, 'patient_id' => $r->patient_id])
            ->shuffle()
            ->take(20_000)
            ->values()
            ->toArray();

        $vaOptions    = ['6/6', '6/9', '6/12', '6/18', '6/24', '6/60'];
        $lidsOptions  = ['Normal', 'Mild Ptosis', 'Blepharitis'];
        $conjOptions  = ['Clear', 'Injection', 'Pale'];
        $corneaOpts   = ['Clear', 'Punctate Epitheliopathy', 'Scar'];
        $lensOptions  = ['Clear', 'Trace NS', 'Grade 2 Cataract'];
        $fundusOpts   = ['Flat and Attached', 'Normal C/D', 'No Hemorrhage'];

        $this->seedRaw('consultations', 20_000, function (int $batch) use (
            $faker, $userIds, $clearancePairs,
            $vaOptions, $lidsOptions, $conjOptions, $corneaOpts, $lensOptions, $fundusOpts
        ) {
            static $offset = 0;
            $rows = [];
            $slice = array_slice($clearancePairs, $offset, $batch);
            $offset += $batch;
            foreach ($slice as $pair) {
                $rows[] = [
                    'user_id'        => $userIds[array_rand($userIds)],
                    'patient_id'     => $pair['patient_id'],
                    'clearance_id'   => $pair['clearance_id'],
                    'chiefComplaint' => $faker->sentence(5),
                    'others'         => $faker->optional(0.3)->sentence(10),
                    'vaOD6m'         => $vaOptions[array_rand($vaOptions)],
                    'vaOS6m'         => $vaOptions[array_rand($vaOptions)],
                    'lidsOD'         => $lidsOptions[array_rand($lidsOptions)],
                    'lidsOS'         => $lidsOptions[array_rand($lidsOptions)],
                    'conjunctivaOD'  => $conjOptions[array_rand($conjOptions)],
                    'conjunctivaOS'  => $conjOptions[array_rand($conjOptions)],
                    'corneaOD'       => $corneaOpts[array_rand($corneaOpts)],
                    'corneaOS'       => $corneaOpts[array_rand($corneaOpts)],
                    'irisOD'         => 'Normal',
                    'irisOS'         => 'Normal',
                    'pupilOD'        => 'PERRLA',
                    'pupilOS'        => 'PERRLA',
                    'lensOD'         => $lensOptions[array_rand($lensOptions)],
                    'lensOS'         => $lensOptions[array_rand($lensOptions)],
                    'vitreousOD'     => 'Clear',
                    'vitreousOS'     => 'Clear',
                    'fundusOD'       => $fundusOpts[array_rand($fundusOpts)],
                    'fundusOS'       => $fundusOpts[array_rand($fundusOpts)],
                    'cdrOD'          => round($faker->randomFloat(2, 0.2, 0.4), 2),
                    'cdrOS'          => round($faker->randomFloat(2, 0.2, 0.4), 2),
                    'IOPOD'          => rand(10, 21),
                    'IOPOS'          => rand(10, 21),
                    'notes'          => $faker->optional(0.8)->paragraph(2),
                    'review'         => $faker->optional(0.5)->sentence(10),
                    'drug_id'        => null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }
            return $rows;
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $this->command->info('Done — 120 000 rows added.');
    }

    // Eloquent factory chunking (for models needing model events/observers)
    private function seedInChunks(int $total, callable $fn): void
    {
        $seeded = 0;
        while ($seeded < $total) {
            $batch   = min(self::CHUNK, $total - $seeded);
            $fn($batch);
            $seeded += $batch;
            $this->command->getOutput()->write("\r  $seeded / $total");
        }
        $this->command->line('');
    }

    // Raw bulk insertOrIgnore (skips unique constraint collisions silently)
    private function seedRaw(string $table, int $target, callable $rowsFn): void
    {
        $startCount = DB::table($table)->count();
        $added      = 0;

        while ($added < $target) {
            $rows   = $rowsFn(self::CHUNK);
            $before = DB::table($table)->count();
            DB::table($table)->insertOrIgnore($rows);
            $added  = DB::table($table)->count() - $startCount;
            $this->command->getOutput()->write("\r  $added / $target");
        }
        $this->command->line('');
    }
}
