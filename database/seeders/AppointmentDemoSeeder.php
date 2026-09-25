<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppointmentDemoSeeder extends Seeder
{
    private const TARGET = 80_000;
    private const CHUNK  = 500;

    private const TITLES = [
        'Follow-up Consultation',
        'Annual Eye Exam',
        'Spectacle Collection',
        'Post-Op Review',
        'Contact Lens Fitting',
        'Glaucoma Check',
        'Diabetic Eye Review',
        'Pediatric Eye Exam',
        'Pre-Op Assessment',
        'Refraction Recheck',
        'Dry Eye Review',
        'Retinal Screening',
        'Low Vision Assessment',
        'Cataract Evaluation',
        'Urgent Eye Review',
    ];

    public function run(): void
    {
        $existing = DB::table('appointments')->count();
        $toSeed   = self::TARGET - $existing;

        if ($toSeed <= 0) {
            $this->command->info('AppointmentDemoSeeder: already at target (' . number_format($existing) . ' appointments).');
            return;
        }

        $patientIds   = DB::table('patients')->pluck('id')->toArray();
        $userIds      = DB::table('users')->pluck('id')->toArray();
        $patientCount = count($patientIds);

        if (empty($patientIds)) {
            $this->command->error('No patients found. Run PatientDemoSeeder first.');
            return;
        }

        $now       = Carbon::now();
        $start     = Carbon::create(2020, 1, 1);
        $futureEnd = $now->copy()->addDays(90);

        $this->command->info("Seeding {$toSeed} appointments (target: " . number_format(self::TARGET) . ')...');
        $bar = $this->command->getOutput()->createProgressBar($toSeed);
        $bar->start();

        $seeded = 0;
        while ($seeded < $toSeed) {
            $count = min(self::CHUNK, $toSeed - $seeded);
            $rows  = [];

            for ($i = 0; $i < $count; $i++) {
                $r = rand(1, 100);

                if ($r <= 70) {
                    // Past — completed
                    $scheduledAt = Carbon::createFromTimestamp(rand($start->timestamp, $now->subHours(1)->timestamp));
                    $status      = 'completed';
                } elseif ($r <= 80) {
                    // Past — cancelled
                    $scheduledAt = Carbon::createFromTimestamp(rand($start->timestamp, $now->subHours(1)->timestamp));
                    $status      = 'cancelled';
                } elseif ($r <= 85) {
                    // Past — missed
                    $scheduledAt = Carbon::createFromTimestamp(rand($start->timestamp, $now->subHours(1)->timestamp));
                    $status      = 'missed';
                } else {
                    // Future — scheduled
                    $scheduledAt = Carbon::createFromTimestamp(rand($now->addHour()->timestamp, $futureEnd->timestamp));
                    $status      = 'scheduled';
                }

                // Appointment was booked 1–30 days before the scheduled time
                $createdAt = $scheduledAt->copy()->subDays(rand(1, 30));
                if ($createdAt->lt($start)) {
                    $createdAt = $start->copy();
                }

                $rows[] = [
                    'patient_id'   => $patientIds[rand(0, $patientCount - 1)],
                    'user_id'      => $userIds[array_rand($userIds)],
                    'title'        => self::TITLES[array_rand(self::TITLES)],
                    'notes'        => null,
                    'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                    'status'       => $status,
                    'created_at'   => $createdAt->format('Y-m-d H:i:s'),
                    'updated_at'   => $createdAt->format('Y-m-d H:i:s'),
                ];
            }

            DB::table('appointments')->insert($rows);
            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('AppointmentDemoSeeder done — ' . number_format(DB::table('appointments')->count()) . ' total appointments.');
    }
}
