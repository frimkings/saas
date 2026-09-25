<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PatientDemoSeeder extends Seeder
{
    private const TARGET = 80_000;
    private const CHUNK  = 500;

    private const MALE_NAMES = [
        'Kwame','Kofi','Yaw','Kweku','Kwabena','Emmanuel','Joseph','Samuel',
        'Daniel','Michael','Isaac','Eric','Frank','George','Richard','Benjamin',
        'Aaron','Elijah','Patrick','John','David','Peter','Philip','Solomon',
        'Nana','Ato','Kojo','Fiifi','Kobby','Kwasi','Enoch','Caleb','Joshua',
        'Nathaniel','Felix','Henry','Alfred','Victor','Roland','Ernest',
    ];
    private const FEMALE_NAMES = [
        'Akosua','Abena','Akua','Afua','Ama','Grace','Comfort','Priscilla',
        'Esther','Agnes','Patience','Doris','Abigail','Felicity','Adwoa',
        'Esi','Adjoa','Yaa','Afia','Rejoice','Nana','Maame','Efua','Ekua',
        'Araba','Adiza','Yayra','Selorm','Dzifa','Ewurama','Sandra','Christiana',
        'Victoria','Beatrice','Florence','Margaret','Rosina','Cecilia','Vida',
    ];
    private const SURNAMES = [
        'Mensah','Asante','Boateng','Owusu','Agyei','Amoah','Ampofo','Antwi',
        'Appiah','Acheampong','Frimpong','Osei','Asiedu','Darko','Adjei','Gyan',
        'Aidoo','Fosu','Ofori','Quaye','Tetteh','Opoku','Badu','Asare','Poku',
        'Bekoe','Kyei','Marfo','Bonsu','Donkor','Asamoah','Sarkodie','Forson',
        'Ennin','Ntiamoah','Agyemang','Adusei','Afari','Agyapong','Obeng',
        'Baah','Nsiah','Twum','Buabeng','Danso','Yeboah','Abban','Essel',
        'Addae','Acquah','Acheampong','Annan','Attah','Awuah','Ayim','Asomani',
    ];
    private const CITIES = [
        'Accra','Kumasi','Tema','Takoradi','Cape Coast','Sunyani','Tamale',
        'Koforidua','Ho','Bolgatanga','Wa','Techiman','Obuasi','Tarkwa',
        'Winneba','Kasoa','Madina','Adentan','Spintex','Ashaiman','Dansoman',
        'Lapaz','Aflao','Prestea','Bibiani','Berekum','Oda','Sefwi Wiawso',
        'Kintampo','Nkawkaw','Nsawam','Begoro','Kade','Atebubu','Ejura',
    ];
    private const OCCUPATIONS = [
        'Farmer','Teacher','Trader','Driver','Nurse','Student','Accountant',
        'Engineer','Lawyer','Secretary','Mechanic','Carpenter','Tailor',
        'Banker','Police Officer','Civil Servant','Pastor','Business Owner',
        'Seamstress','Hairdresser','Electrician','Plumber','Chef','Retired',
        'Unemployed','Doctor','Pharmacist','IT Professional','Security Officer',
        'Mason','Welder','Fisherman','Photographer','Journalist','Soldier',
    ];
    private const CIVIL_STATUSES = [
        'single','single','married','married','married','married','divorced','widowed',
    ];
    private const PREFIXES = [
        '+23320','+23323','+23324','+23325','+23326',
        '+23327','+23328','+23329','+23350','+23354',
        '+23355','+23359',
    ];

    public function run(): void
    {
        $existing = DB::table('patients')->count();
        $toSeed   = self::TARGET - $existing;

        if ($toSeed <= 0) {
            $this->command->info('PatientDemoSeeder: already at target (' . number_format($existing) . ' patients).');
            return;
        }

        $adminId = DB::table('users')->value('id');
        $maxNum  = (int) DB::table('patients')
            ->selectRaw("MAX(CAST(SUBSTRING(pxnumber, 3) AS UNSIGNED)) as m")
            ->value('m');

        $start = Carbon::create(2020, 1, 1)->timestamp;
        $end   = Carbon::now()->timestamp;
        $mid   = (int) (($start + $end) / 2);

        $this->command->info("Seeding {$toSeed} patients (target: " . number_format(self::TARGET) . ')...');
        $bar = $this->command->getOutput()->createProgressBar($toSeed);
        $bar->start();

        $seeded = 0;
        while ($seeded < $toSeed) {
            $count = min(self::CHUNK, $toSeed - $seeded);
            $rows  = [];

            for ($i = 0; $i < $count; $i++) {
                $isFemale  = rand(1, 100) <= 52;
                $firstName = $isFemale
                    ? self::FEMALE_NAMES[array_rand(self::FEMALE_NAMES)]
                    : self::MALE_NAMES[array_rand(self::MALE_NAMES)];
                // 60% of patients registered in the more recent half of the range (growth curve)
                $ts        = rand(1, 10) <= 6 ? rand($mid, $end) : rand($start, $end);
                $createdAt = Carbon::createFromTimestamp($ts)->format('Y-m-d H:i:s');

                $rows[] = [
                    'uuid'         => (string) Str::uuid(),
                    'user_id'      => $adminId,
                    'pxnumber'     => 'PX' . str_pad($maxNum + $seeded + $i + 1, 6, '0', STR_PAD_LEFT),
                    'name'         => $firstName . ' ' . self::SURNAMES[array_rand(self::SURNAMES)],
                    'gender'       => $isFemale ? 'Female' : 'Male',
                    'dob'          => Carbon::now()->subYears(rand(5, 80))->subDays(rand(0, 364))->format('Y-m-d'),
                    'contact'      => self::PREFIXES[array_rand(self::PREFIXES)] . rand(1000000, 9999999),
                    'address'      => self::CITIES[array_rand(self::CITIES)],
                    'occupation'   => self::OCCUPATIONS[array_rand(self::OCCUPATIONS)],
                    'civil_status' => self::CIVIL_STATUSES[array_rand(self::CIVIL_STATUSES)],
                    'email'        => null,
                    'created_at'   => $createdAt,
                    'updated_at'   => $createdAt,
                ];
            }

            DB::table('patients')->insert($rows);
            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('PatientDemoSeeder done — ' . number_format(DB::table('patients')->count()) . ' total patients.');
    }
}
