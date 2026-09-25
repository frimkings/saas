<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClearanceDemoSeeder extends Seeder
{
    private const TARGET = 320_000;
    private const CHUNK  = 500;

    public function run(): void
    {
        $existing = DB::table('cashier_patient_clearances')->count();
        $toSeed   = self::TARGET - $existing;

        if ($toSeed <= 0) {
            $this->command->info('ClearanceDemoSeeder: already at target (' . number_format($existing) . ' clearances).');
            return;
        }

        $patientIds = DB::table('patients')->pluck('id')->toArray();
        if (empty($patientIds)) {
            $this->command->error('No patients found. Run PatientDemoSeeder first.');
            return;
        }

        $userIds      = DB::table('users')->pluck('id')->toArray();
        $patientCount = count($patientIds);
        $start        = Carbon::create(2020, 1, 1)->timestamp;
        $end          = Carbon::now()->timestamp;
        $sevenDaysAgo = Carbon::now()->subDays(7)->startOfDay()->format('Y-m-d');

        $this->command->info("Seeding {$toSeed} clearances (target: " . number_format(self::TARGET) . ')...');
        $bar = $this->command->getOutput()->createProgressBar($toSeed);
        $bar->start();

        $seeded = 0;
        while ($seeded < $toSeed) {
            $count = min(self::CHUNK, $toSeed - $seeded);
            $rows  = [];

            for ($i = 0; $i < $count; $i++) {
                $patientId = $patientIds[rand(0, $patientCount - 1)];
                $date      = Carbon::createFromTimestamp(rand($start, $end))->format('Y-m-d');
                $r         = rand(1, 100);

                $rows[] = [
                    'uuid'           => (string) Str::uuid(),
                    'user_id'        => $userIds[array_rand($userIds)],
                    'patient_id'     => $patientId,
                    'clearance_date' => $date,
                    'payment_status' => $r <= 80 ? 'paid' : ($r <= 95 ? 'partial' : 'pending'),
                    'doctor_status'  => $date < $sevenDaysAgo ? 1 : 0,
                    'service_id'     => null,
                    'sale_id'        => null,
                    'created_at'     => $date . ' 08:' . str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00',
                    'updated_at'     => $date . ' 08:' . str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00',
                ];
            }

            // insertOrIgnore handles the (patient_id, clearance_date) unique constraint
            DB::table('cashier_patient_clearances')->insertOrIgnore($rows);
            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('ClearanceDemoSeeder done — ' . number_format(DB::table('cashier_patient_clearances')->count()) . ' total clearances.');
    }
}
