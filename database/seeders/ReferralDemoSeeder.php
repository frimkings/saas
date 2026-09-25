<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferralDemoSeeder extends Seeder
{
    private const TARGET = 5_000;
    private const CHUNK  = 500;

    private const SPECIALISTS = [
        'Regional Eye Centre, Accra',
        'Korle Bu Teaching Hospital Eye Unit',
        'Komfo Anokye Teaching Hospital Eye Clinic',
        'Eye Foundation Hospital',
        'LEKMA Eye Centre',
        '37 Military Hospital Eye Clinic',
        'University of Ghana Medical Centre',
        'Ghana Eye Institute, Accra',
        'Manhyia Hospital Eye Unit, Kumasi',
        'St. Thomas Eye Clinic',
    ];

    private const COMPLAINTS = [
        'Blurred vision','Eye redness','Itchy eyes','Floaters','Eye pain',
        'Discharge from eye','Sensitivity to light','Difficulty reading',
        'Night blindness','Loss of peripheral vision','Double vision',
        'Sudden vision loss','Cloudy vision','Swollen eyelids','Flashes of light',
    ];

    private const DIAGNOSES = [
        'Suspected glaucoma','Open angle glaucoma','Cataract','Diabetic retinopathy',
        'Age-related macular degeneration','Retinal detachment','Uveitis',
        'Corneal ulcer','Pterygium','Dry eye syndrome','Amblyopia',
        'Strabismus','Keratoconus','Ocular hypertension','Viral conjunctivitis',
    ];

    private const REASONS = [
        'Further evaluation and specialist management',
        'Surgical management required',
        'Second opinion requested',
        'Specialist retinal assessment',
        'Vitreoretinal assessment',
        'Paediatric ophthalmology review',
        'Low vision assessment',
        'Neuro-ophthalmic evaluation',
        'Corneal specialist review',
        'Glaucoma specialist management',
    ];

    private const VA_OPTIONS = ['6/6','6/9','6/12','6/18','6/24','6/36','6/60','CF','HM'];

    public function run(): void
    {
        $existing = DB::table('referrals')->count();
        $toSeed   = self::TARGET - $existing;

        if ($toSeed <= 0) {
            $this->command->info('ReferralDemoSeeder: already at target (' . number_format($existing) . ' referrals).');
            return;
        }

        $patientIds   = DB::table('patients')->pluck('id')->toArray();
        $patients     = DB::table('patients')->select('id', 'name', 'contact')->get()->keyBy('id');
        $userIds      = DB::table('users')->pluck('id')->toArray();
        $patientCount = count($patientIds);

        if (empty($patientIds)) {
            $this->command->error('No patients found. Run PatientDemoSeeder first.');
            return;
        }

        $start = Carbon::create(2020, 1, 1)->timestamp;
        $end   = Carbon::now()->timestamp;

        $this->command->info("Seeding {$toSeed} referrals (target: " . number_format(self::TARGET) . ')...');
        $bar = $this->command->getOutput()->createProgressBar($toSeed);
        $bar->start();

        $seeded = 0;
        while ($seeded < $toSeed) {
            $count = min(self::CHUNK, $toSeed - $seeded);
            $rows  = [];

            for ($i = 0; $i < $count; $i++) {
                $date      = Carbon::createFromTimestamp(rand($start, $end))->format('Y-m-d');
                $r         = rand(1, 100);
                $status    = $r <= 65 ? 'completed' : ($r <= 90 ? 'pending' : 'cancelled');
                $noPatient = rand(1, 10) === 1; // 10% anonymous

                $patientId      = null;
                $patientName    = 'Anonymous Patient';
                $patientContact = null;

                if (!$noPatient) {
                    $pid            = $patientIds[rand(0, $patientCount - 1)];
                    $p              = $patients[$pid] ?? null;
                    $patientId      = $pid;
                    $patientName    = $p->name ?? 'Unknown';
                    $patientContact = $p->contact ?? null;
                }

                $rows[] = [
                    'referred_by'        => $userIds[array_rand($userIds)],
                    'patient_id'         => $patientId,
                    'referral_to'        => self::SPECIALISTS[array_rand(self::SPECIALISTS)],
                    'referral_date'      => $date,
                    'patient_name'       => $patientName,
                    'patient_age_sex'    => rand(5, 80) . ' yrs / ' . (rand(0, 1) ? 'M' : 'F'),
                    'patient_contact'    => $patientContact,
                    'complaint'          => self::COMPLAINTS[array_rand(self::COMPLAINTS)],
                    'va_od'              => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                    'va_os'              => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                    'refraction'         => null,
                    'anterior_segment'   => 'See attached examination findings',
                    'posterior_segment'  => null,
                    'iop'                => rand(10, 24) . '/' . rand(10, 24) . ' mmHg',
                    'diagnosis'          => self::DIAGNOSES[array_rand(self::DIAGNOSES)],
                    'reason_for_referral'=> self::REASONS[array_rand(self::REASONS)],
                    'management'         => null,
                    'status'             => $status,
                    'created_at'         => $date . ' 10:00:00',
                    'updated_at'         => $date . ' 10:00:00',
                ];
            }

            DB::table('referrals')->insert($rows);
            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('ReferralDemoSeeder done — ' . number_format(DB::table('referrals')->count()) . ' total referrals.');
    }
}
