<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Comprehensive live-demonstration seeder.
 *
 * Date window : May 1 2025 → May 28 2026
 * PRO license : 2-year trial activated (expires ~May 2028)
 *
 * Run: php artisan db:seed --class=LiveDemoSeeder
 */
class LiveDemoSeeder extends Seeder
{
    // ── Date window ───────────────────────────────────────────────────────────
    private Carbon $start;
    private Carbon $end;

    // ── Shared lookup arrays (populated incrementally) ────────────────────────
    private array $userIds      = [];
    private array $patientIds   = [];
    private array $productMap   = [];   // [['id','cost','sell','name','cat'], ...]
    private array $categoryIds  = [];   // name => id
    private array $diagnosisIds = [];

    // ── Credentials ───────────────────────────────────────────────────────────
    private const SUPERADMIN_EMAIL = 'frimkings@gmail.com';
    private const SUPERADMIN_PASS  = 'password';

    // ── Name pools ────────────────────────────────────────────────────────────
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
        'Esi','Adjoa','Yaa','Afia','Rejoice','Maame','Efua','Ekua',
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
    ];
    private const CITIES = [
        'Accra','Kumasi','Tema','Takoradi','Cape Coast','Sunyani','Tamale',
        'Koforidua','Ho','Bolgatanga','Wa','Techiman','Obuasi','Tarkwa',
        'Winneba','Kasoa','Madina','Adentan','Spintex','Ashaiman','Dansoman',
        'Lapaz','Aflao','Nsawam','Begoro','Nkawkaw','Ejura',
    ];
    private const OCCUPATIONS = [
        'Farmer','Teacher','Trader','Driver','Nurse','Student','Accountant',
        'Engineer','Lawyer','Secretary','Mechanic','Carpenter','Tailor',
        'Banker','Police Officer','Civil Servant','Pastor','Business Owner',
        'Seamstress','Hairdresser','Electrician','Plumber','Chef','Retired',
        'Unemployed','Doctor','Pharmacist','IT Professional','Security Officer',
        'Mason','Welder','Fisherman','Journalist','Soldier',
    ];
    private const PREFIXES = [
        '+23320','+23323','+23324','+23325','+23326',
        '+23327','+23328','+23329','+23350','+23354',
        '+23355','+23359',
    ];

    // ── Clinical data ─────────────────────────────────────────────────────────
    private const COMPLAINTS = [
        'Blurred vision','Eye redness','Itchy eyes','Foreign body sensation',
        'Watery eyes','Headache with eye strain','Double vision','Floaters',
        'Eye pain','Discharge from eye','Sensitivity to light','Difficulty reading',
        'Night blindness','Loss of peripheral vision','Eye fatigue after screen use',
        'Dry eyes','Burning sensation','Swollen eyelids','Flashes of light',
        'Blurred near vision','Cloudy vision','Reduced colour vision','Squinting',
    ];
    private const VA_OPTIONS   = ['6/6','6/9','6/12','6/18','6/24','6/36','6/60','CF','HM','PL'];
    private const FINDINGS     = ['Normal','Mild congestion','Mild haziness','Clear','Reactive','Normal size','Slightly dilated','Irregular'];
    private const CDR_VALUES   = ['0.2','0.3','0.3','0.4','0.4','0.4','0.5','0.5','0.6','0.7'];
    private const LENS_TYPES   = ['Single Vision','Progressive','Bifocal','Anti-Reflective','Photochromic'];
    private const APPT_TITLES  = [
        'Follow-up Consultation','Annual Eye Exam','Spectacle Collection',
        'Post-Op Review','Contact Lens Fitting','Glaucoma Check',
        'Diabetic Eye Review','Pediatric Eye Exam','Pre-Op Assessment',
        'Refraction Recheck','Dry Eye Review','Retinal Screening',
        'Low Vision Assessment','Cataract Evaluation','Urgent Eye Review',
    ];

    // ── Products & categories ─────────────────────────────────────────────────
    private const CATEGORIES = [
        'Frames'         => 'product',
        'Lenses'         => 'product',
        'Eye Drops'      => 'product',
        'Contact Lenses' => 'product',
        'Sunglasses'     => 'product',
        'Accessories'    => 'product',
        'Drugs'          => 'product',
        'Services'       => 'service',
    ];

    private const PRODUCTS = [
        // [name, category, cost, selling_price]
        ['Ray-Ban RB2140',           'Frames',         150,  280],
        ['Oakley Holbrook',           'Frames',         180,  350],
        ['Silhouette Titan',          'Frames',         200,  420],
        ['Gucci GG0010O',             'Frames',         250,  520],
        ['Generic Frame A',           'Frames',          40,   90],
        ['Generic Frame B',           'Frames',          35,   80],
        ['Titanium Frame',            'Frames',         120,  240],
        ['Acetate Frame Classic',     'Frames',          60,  130],
        ['Single Vision CR-39',       'Lenses',          50,  110],
        ['Progressive CR-39',         'Lenses',         120,  260],
        ['Anti-Reflective Lens',      'Lenses',          80,  175],
        ['Photochromic Lens',         'Lenses',         130,  290],
        ['Blue-Light Blocking Lens',  'Lenses',          90,  200],
        ['Bifocal Lens',              'Lenses',          70,  155],
        ['High-Index 1.67',           'Lenses',         160,  340],
        ['Visine Original 15ml',      'Eye Drops',        8,   18],
        ['Tears Naturale 15ml',       'Eye Drops',       12,   25],
        ['Tobradex Eye Drop 5ml',     'Eye Drops',       25,   52],
        ['Maxitrol Eye Drop 5ml',     'Eye Drops',       20,   42],
        ['Voltaren Ophtha 5ml',       'Eye Drops',       18,   38],
        ['Betoptic 5ml',              'Eye Drops',       22,   46],
        ['Timolol 0.5% 5ml',          'Eye Drops',       15,   32],
        ['Acuvue Oasys (6pk)',        'Contact Lenses',  45,   95],
        ['Dailies Total1 (30pk)',     'Contact Lenses',  60,  128],
        ['Biofinity Monthly (6pk)',   'Contact Lenses',  50,  108],
        ['Air Optix Plus (6pk)',      'Contact Lenses',  48,  102],
        ['Polarised Sunglasses',      'Sunglasses',      60,  130],
        ['Sport Wrap Sunglasses',     'Sunglasses',      70,  150],
        ['Driving Sunglasses',        'Sunglasses',      55,  118],
        ['Microfibre Cloth',          'Accessories',      2,    6],
        ['Hard Eyeglass Case',        'Accessories',      5,   12],
        ['Anti-Fog Spray 30ml',       'Accessories',      8,   18],
        ['Neck Cord / Lanyard',       'Accessories',      3,    8],
        ['Repair Kit',                'Accessories',      4,   10],
        ['Chloramphenicol Eye Oint',  'Drugs',            5,   12],
        ['Gentamicin Eye Drop 5ml',   'Drugs',           10,   22],
        ['Dexamethasone Eye Drop',    'Drugs',           14,   30],
        ['Fusidic Acid Eye Gel',      'Drugs',           16,   34],
        ['Eye Examination',           'Services',         0,   80],
        ['Contact Lens Fitting',      'Services',         0,   60],
        ['Frame Adjustment',          'Services',         0,   20],
        ['Lens Replacement',          'Services',         0,   45],
    ];

    private const PAYMENT_METHODS = ['cash', 'momo', 'card', 'bank_transfer', 'insurance'];
    private const FREQUENCIES     = ['OD', 'OS', 'OU', 'BD', 'TDS', null];
    private const EYES            = ['OD', 'OS', 'OU', null];

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function run(): void
    {
        $this->start = Carbon::create(2025, 5, 1);
        $this->end   = Carbon::create(2026, 5, 28);

        $this->printHeader();

        // ── Foundation seeders ────────────────────────────────────────────────
        $this->call([
            DiagnosisSeeder::class,
            DefaultIncomeStatementTemplateSeeder::class,
        ]);

        // ── System setup ──────────────────────────────────────────────────────
        $this->step('Setting up users and roles...');
        $this->seedUsers();

        $this->step('Configuring clinic settings and 2-year PRO license...');
        $this->seedSettings();

        $this->step('Seeding product categories and inventory...');
        $this->seedCategoriesAndProducts();

        $this->step('Seeding suppliers...');
        $this->seedSuppliers();

        $this->step('Seeding SMS templates...');
        $this->seedSmsTemplates();

        // Reload shared lookups after setup
        $this->userIds      = DB::table('users')->pluck('id')->toArray();
        $this->diagnosisIds = DB::table('diagnoses')->pluck('id')->toArray();

        // ── Clinical chain ────────────────────────────────────────────────────
        $this->step('Seeding patients (May 2025 – May 2026)...');
        $this->seedPatients();

        $this->step('Seeding patient clearances (check-ins)...');
        $this->seedClearances();

        $this->step('Seeding consultations, eye exams and refractions...');
        $this->seedConsultations();

        $this->step('Seeding spectacle lens orders...');
        $this->seedLensOrders();

        $this->step('Seeding appointments...');
        $this->seedAppointments();

        $this->step('Seeding referral letters...');
        $this->seedReferrals();

        // ── Commercial ────────────────────────────────────────────────────────
        $this->step('Seeding POS sales, items and payments...');
        $this->seedSales();

        $this->step('Seeding monthly expenses...');
        $this->seedExpenses();

        $this->step('Seeding income statement entries...');
        $this->seedIncomeStatementEntries();

        $this->step('Seeding quotations...');
        $this->seedQuotations();

        $this->step('Seeding purchase orders and stock movements...');
        $this->seedPurchaseOrders();

        // ── Approval workflows ────────────────────────────────────────────────
        $this->step('Seeding discount approval requests...');
        $this->seedDiscountApprovals();

        $this->step('Seeding refund logs...');
        $this->seedRefundLogs();

        // ── System / audit ────────────────────────────────────────────────────
        $this->step('Seeding login history...');
        $this->seedLoginLogs();

        $this->step('Seeding SMS logs...');
        $this->seedSmsLogs();

        $this->step('Seeding in-app notifications...');
        $this->seedAppNotifications();

        $this->step('Seeding staff messages...');
        $this->seedStaffMessages();

        $this->step('Seeding audit trail...');
        $this->seedAuditTrail();

        $this->printFooter();
    }

    // =========================================================================
    // USERS & ROLES
    // =========================================================================

    private function seedUsers(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['manage users','view consultations','perform refraction','manage billing','approve clearance revoke'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin']);
        $superAdmin->syncPermissions(Permission::all());
        foreach (['Doctor','Cashier','Staff','Manager','Secretary'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }

        $staff = [
            [self::SUPERADMIN_EMAIL,            'Dr. Kingsford Frimpong',  'Super Admin'],
            ['dr.amankwah@eyeclinic.com',        'Dr. Kwame Amankwah',      'Doctor'     ],
            ['secretary@eyeclinic.com',          'Ama Boateng',             'Secretary'  ],
            ['cashier@eyeclinic.com',            'Kofi Asante',             'Cashier'    ],
            ['manager@eyeclinic.com',            'Yaw Mensah',              'Manager'    ],
        ];

        foreach ($staff as [$email, $name, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make(self::SUPERADMIN_PASS), 'email_verified_at' => now()]
            );
            if (!$user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        $this->note('5 staff accounts ready (all password: "' . self::SUPERADMIN_PASS . '")');
    }

    // =========================================================================
    // SETTINGS & PRO LICENSE
    // =========================================================================

    private function seedSettings(): void
    {
        // PRO-via-trial trick:
        //   isInTrial() returns true when now() <= (trial_started_at + 30 days)
        //   Setting trial_started_at = today + 700 days → trial expires today + 730 days ≈ 2 years
        $trialStartedAt  = Carbon::now()->addDays(700)->toDateString();
        $licenseExpiry   = Carbon::now()->addDays(730)->toDateString();

        $existing = DB::table('settings')->first();

        $data = [
            'clinic_name'      => 'VisionCare Eye Clinic',
            'clinic_address'   => 'No. 12 Liberation Road, Accra, Ghana',
            'clinic_contact'   => '+233 20 000 1234',
            'clinic_email'     => 'info@visioncareclinic.com',
            'trial_started_at' => $trialStartedAt,
            'va_notation'      => '6m',
            'currency_symbol'  => 'GH₵',
            'updated_at'       => now(),
        ];

        if ($existing) {
            $data['installation_id'] = $existing->installation_id ?? (string) Str::uuid();
            DB::table('settings')->where('id', $existing->id)->update($data);
        } else {
            $data['installation_id'] = (string) Str::uuid();
            $data['created_at']      = now();
            DB::table('settings')->insert($data);
        }

        \App\Services\LicenseService::clearCache();
        $this->note("PRO trial active — all PRO features unlocked until {$licenseExpiry} (~2 years)");
    }

    // =========================================================================
    // CATEGORIES & PRODUCTS
    // =========================================================================

    private function seedCategoriesAndProducts(): void
    {
        $adminId = DB::table('users')->value('id');

        foreach (self::CATEGORIES as $name => $type) {
            $row = DB::table('categories')->where('name', $name)->first();
            $this->categoryIds[$name] = $row
                ? $row->id
                : DB::table('categories')->insertGetId([
                    'user_id'    => $adminId,
                    'name'       => $name,
                    'type'       => $type,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $this->productMap = [];
        foreach (self::PRODUCTS as [$name, $catName, $cost, $sell]) {
            $row = DB::table('products')->where('name', $name)->first();
            $id  = $row
                ? $row->id
                : DB::table('products')->insertGetId([
                    'user_id'       => $adminId,
                    'name'          => $name,
                    'category_id'   => $this->categoryIds[$catName],
                    'quantity'      => rand(50, 300),
                    'cost_price'    => $cost,
                    'selling_price' => $sell,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            $this->productMap[] = ['id' => $id, 'cost' => $cost, 'sell' => $sell, 'name' => $name, 'cat' => $catName];
        }

        $this->note(count($this->categoryIds) . ' categories, ' . count($this->productMap) . ' products ready.');
    }

    // =========================================================================
    // SUPPLIERS
    // =========================================================================

    private function seedSuppliers(): void
    {
        $suppliers = [
            ['Vision Medical Supplies Ltd',   'Kojo Aidoo',      '+233 24 111 2233', 'sales@visionmedical.gh',     'Accra Industrial Area',      7 ],
            ['Optic Solutions Ghana',          'Abena Ofori',     '+233 20 444 5566', 'info@opticsolutions.gh',     'Ring Road Central, Accra',  10 ],
            ['African Eye Care Ltd',           'Emmanuel Darko',  '+233 27 777 8899', 'orders@africaneye.gh',       'Kumasi City Centre',         14 ],
            ['Global Lens Distributors',       'Priscilla Tetteh','+233 50 222 3344', 'procurement@globallens.com', 'Tema Community 8',            5 ],
            ['MedSupply Ghana Ltd',            'Samuel Osei',     '+233 24 555 6677', 'samuel@medsupply.gh',        'Accra New Town',              7 ],
            ['Ophthalmic Equipment Services',  'Nana Asante',     '+233 20 888 9900', 'nana@ophthalmic-equip.gh',   'East Legon, Accra',          10 ],
            ['PharmaCare Wholesale',           'Joseph Mensah',   '+233 23 333 4455', 'joseph@pharmacare.gh',       'Spintex Road, Accra',         3 ],
            ['Eye Health Imports',             'Grace Boateng',   '+233 26 666 7788', 'grace@eyehealthimports.gh',  'Takoradi Business District',  10 ],
        ];

        foreach ($suppliers as [$name, $contact, $phone, $email, $address, $lead]) {
            DB::table('suppliers')->updateOrInsert(
                ['name' => $name],
                [
                    'contact_person' => $contact,
                    'phone'          => $phone,
                    'email'          => $email,
                    'address'        => $address,
                    'lead_time_days' => $lead,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]
            );
        }

        $this->note(DB::table('suppliers')->count() . ' suppliers ready.');
    }

    // =========================================================================
    // SMS TEMPLATES
    // =========================================================================

    private function seedSmsTemplates(): void
    {
        $templates = [
            [
                'key'          => 'appointment_reminder',
                'label'        => 'Appointment Reminder',
                'message'      => 'Dear {patient_name}, this is a reminder of your appointment at VisionCare Eye Clinic on {date} at {time}. Please call +233 20 000 1234 if you need to reschedule.',
                'placeholders' => json_encode(['patient_name', 'date', 'time']),
            ],
            [
                'key'          => 'birthday_wishes',
                'label'        => 'Birthday Wishes',
                'message'      => 'Happy Birthday, {patient_name}! Wishing you a wonderful day from all of us at VisionCare Eye Clinic. Don\'t forget your annual eye check-up!',
                'placeholders' => json_encode(['patient_name']),
            ],
            [
                'key'          => 'recall_reminder',
                'label'        => 'Recall Reminder',
                'message'      => 'Dear {patient_name}, it has been a while since your last visit to VisionCare Eye Clinic. We recommend scheduling your eye examination. Call us at +233 20 000 1234.',
                'placeholders' => json_encode(['patient_name']),
            ],
            [
                'key'          => 'spectacle_renewal',
                'label'        => 'Spectacle Renewal',
                'message'      => 'Dear {patient_name}, your spectacle prescription from VisionCare Eye Clinic is due for renewal. Please visit us to update your lenses. Call +233 20 000 1234.',
                'placeholders' => json_encode(['patient_name']),
            ],
            [
                'key'          => 'custom_broadcast',
                'label'        => 'Custom Broadcast',
                'message'      => 'Dear {patient_name}, {message}. - VisionCare Eye Clinic',
                'placeholders' => json_encode(['patient_name', 'message']),
            ],
        ];

        foreach ($templates as $t) {
            DB::table('sms_templates')->updateOrInsert(
                ['key' => $t['key']],
                array_merge($t, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        $this->note(count($templates) . ' SMS templates ready.');
    }

    // =========================================================================
    // PATIENTS
    // =========================================================================

    private function seedPatients(): void
    {
        $target   = 400;
        $existing = DB::table('patients')->count();

        if ($existing >= $target) {
            $this->note("Patients already at {$existing}, loading IDs.");
            $this->patientIds = DB::table('patients')->pluck('id')->toArray();
            return;
        }

        $toSeed  = $target - $existing;
        $adminId = DB::table('users')->value('id');
        $maxNum  = (int) DB::table('patients')
            ->selectRaw('MAX(CAST(SUBSTRING(pxnumber, 3) AS UNSIGNED)) as m')
            ->value('m');

        $startTs = $this->start->timestamp;
        $endTs   = $this->end->timestamp;
        $midTs   = (int) (($startTs + $endTs) / 2);

        $rows = [];
        for ($i = 0; $i < $toSeed; $i++) {
            $isFemale  = rand(1, 100) <= 52;
            $namePool  = $isFemale ? self::FEMALE_NAMES : self::MALE_NAMES;
            // 65% of registrations skewed to the more recent half of the window
            $ts        = rand(1, 100) <= 65 ? rand($midTs, $endTs) : rand($startTs, $endTs);
            $createdAt = Carbon::createFromTimestamp($ts)->format('Y-m-d H:i:s');

            $rows[] = [
                'uuid'         => (string) Str::uuid(),
                'user_id'      => $adminId,
                'pxnumber'     => 'PX' . str_pad($maxNum + $existing + $i + 1, 6, '0', STR_PAD_LEFT),
                'name'         => $namePool[array_rand($namePool)] . ' ' . self::SURNAMES[array_rand(self::SURNAMES)],
                'gender'       => $isFemale ? 'Female' : 'Male',
                'dob'          => Carbon::now()->subYears(rand(5, 80))->subDays(rand(0, 364))->format('Y-m-d'),
                'contact'      => self::PREFIXES[array_rand(self::PREFIXES)] . rand(1000000, 9999999),
                'address'      => self::CITIES[array_rand(self::CITIES)],
                'occupation'   => self::OCCUPATIONS[array_rand(self::OCCUPATIONS)],
                'civil_status' => ['single','single','married','married','married','divorced','widowed'][rand(0, 6)],
                'email'        => null,
                'created_at'   => $createdAt,
                'updated_at'   => $createdAt,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('patients')->insert($chunk);
        }

        $this->patientIds = DB::table('patients')->pluck('id')->toArray();
        $this->note(DB::table('patients')->count() . ' patients total.');
    }

    // =========================================================================
    // CLEARANCES (patient check-ins)
    // =========================================================================

    private function seedClearances(): void
    {
        $target   = 600;
        $existing = DB::table('cashier_patient_clearances')->count();

        if ($existing >= $target) {
            $this->note("Clearances already at {$existing}, skipping.");
            return;
        }

        $toSeed       = $target - $existing;
        $patientCount = count($this->patientIds);
        $sevenDaysAgo = $this->end->copy()->subDays(7)->format('Y-m-d');
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;

        $rows = [];
        for ($i = 0; $i < $toSeed; $i++) {
            $date = Carbon::createFromTimestamp(rand($startTs, $endTs))->format('Y-m-d');
            $r    = rand(1, 100);
            $min  = str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT);

            $rows[] = [
                'uuid'           => (string) Str::uuid(),
                'user_id'        => $this->userIds[array_rand($this->userIds)],
                'patient_id'     => $this->patientIds[rand(0, $patientCount - 1)],
                'clearance_date' => $date,
                'payment_status' => $r <= 80 ? 'paid' : ($r <= 95 ? 'partial' : 'pending'),
                'doctor_status'  => $date < $sevenDaysAgo ? 1 : 0,
                'service_id'     => null,
                'sale_id'        => null,
                'created_at'     => $date . ' 08:' . $min . ':00',
                'updated_at'     => $date . ' 08:' . $min . ':00',
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('cashier_patient_clearances')->insertOrIgnore($chunk);
        }

        $this->note(DB::table('cashier_patient_clearances')->count() . ' clearances total.');
    }

    // =========================================================================
    // CONSULTATIONS + REFRACTIONS + DIAGNOSES PIVOT
    // =========================================================================

    private function seedConsultations(): void
    {
        $target   = 500;
        $existing = DB::table('consultations')->count();

        if ($existing >= $target) {
            $this->note("Consultations already at {$existing}, skipping.");
            return;
        }

        $clearances = DB::table('cashier_patient_clearances as c')
            ->leftJoin('consultations as co', 'co.clearance_id', '=', 'c.id')
            ->whereNull('co.id')
            ->select('c.id', 'c.patient_id', 'c.clearance_date', 'c.user_id')
            ->limit($target - $existing)
            ->get()
            ->toArray();

        if (empty($clearances)) {
            $this->note('No clearances available for consultations.');
            return;
        }

        $diagCount = count($this->diagnosisIds);
        $bar       = $this->command->getOutput()->createProgressBar(count($clearances));
        $bar->start();

        foreach (array_chunk($clearances, 200) as $chunk) {
            $consultRows  = [];
            $clearanceIds = [];

            foreach ($chunk as $cl) {
                $iopOD = rand(1, 10) === 1 ? rand(22, 35) : rand(10, 21);
                $iopOS = rand(1, 10) === 1 ? rand(22, 35) : rand(10, 21);
                $hour  = str_pad(rand(8, 17), 2, '0', STR_PAD_LEFT);
                $min   = str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT);
                $dt    = $cl->clearance_date . " {$hour}:{$min}:00";

                $consultRows[]  = [
                    'patient_id'    => $cl->patient_id,
                    'user_id'       => $this->userIds[array_rand($this->userIds)],
                    'clearance_id'  => $cl->id,
                    'chiefComplaint'=> self::COMPLAINTS[array_rand(self::COMPLAINTS)],
                    'vaOD6m'        => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                    'vaOS6m'        => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                    'lidsOD'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'lidsOS'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'conjunctivaOD' => self::FINDINGS[array_rand(self::FINDINGS)],
                    'conjunctivaOS' => self::FINDINGS[array_rand(self::FINDINGS)],
                    'corneaOD'      => self::FINDINGS[array_rand(self::FINDINGS)],
                    'corneaOS'      => self::FINDINGS[array_rand(self::FINDINGS)],
                    'irisOD'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'irisOS'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'pupilOD'       => self::FINDINGS[array_rand(self::FINDINGS)],
                    'pupilOS'       => self::FINDINGS[array_rand(self::FINDINGS)],
                    'lensOD'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'lensOS'        => self::FINDINGS[array_rand(self::FINDINGS)],
                    'vitreousOD'    => self::FINDINGS[array_rand(self::FINDINGS)],
                    'vitreousOS'    => self::FINDINGS[array_rand(self::FINDINGS)],
                    'fundusOD'      => self::FINDINGS[array_rand(self::FINDINGS)],
                    'fundusOS'      => self::FINDINGS[array_rand(self::FINDINGS)],
                    'cdrOD'         => self::CDR_VALUES[array_rand(self::CDR_VALUES)],
                    'cdrOS'         => self::CDR_VALUES[array_rand(self::CDR_VALUES)],
                    'IOPOD'         => $iopOD,
                    'IOPOS'         => $iopOS,
                    'notes'         => null,
                    'review'        => null,
                    'created_at'    => $dt,
                    'updated_at'    => $dt,
                ];
                $clearanceIds[] = $cl->id;
            }

            DB::table('consultations')->insert($consultRows);

            $consultMap = DB::table('consultations')
                ->whereIn('clearance_id', $clearanceIds)
                ->pluck('id', 'clearance_id')
                ->toArray();

            $pivotRows = [];
            $refRows   = [];

            foreach ($consultRows as $cr) {
                $consultId = $consultMap[$cr['clearance_id']] ?? null;
                if (!$consultId) continue;

                // 1–3 diagnoses per consultation
                $numDiag = rand(1, min(3, $diagCount));
                $keys    = $diagCount > 1
                    ? (array) array_rand($this->diagnosisIds, $numDiag)
                    : [0];

                foreach ($keys as $k) {
                    $pivotRows[] = [
                        'consultation_id' => $consultId,
                        'diagnosis_id'    => $this->diagnosisIds[$k],
                        'created_at'      => $cr['created_at'],
                        'updated_at'      => $cr['updated_at'],
                    ];
                }

                // 30% receive a refraction
                if (rand(1, 10) <= 3) {
                    $sph    = sprintf('%+.2f', rand(-1500, 500) / 100);
                    $cyl    = sprintf('%.2f',  -(rand(0, 250)  / 100));
                    $axis   = rand(1, 180);
                    $sphOS  = sprintf('%+.2f', rand(-1500, 500) / 100);
                    $cylOS  = sprintf('%.2f',  -(rand(0, 250)  / 100));
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

            if ($pivotRows) DB::table('consultation_diagnosis')->insert($pivotRows);
            if ($refRows)   DB::table('refractions')->insertOrIgnore($refRows);

            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->command->newLine();
        $this->note(
            DB::table('consultations')->count() . ' consultations, '
            . DB::table('refractions')->count() . ' refractions, '
            . DB::table('consultation_diagnosis')->count() . ' diagnoses linked.'
        );
    }

    // =========================================================================
    // LENS ORDERS
    // =========================================================================

    private function seedLensOrders(): void
    {
        if (DB::table('lens_orders')->count() > 0) {
            $this->note('Lens orders already seeded, skipping.');
            return;
        }

        $refractions = DB::table('refractions as r')
            ->leftJoin('lens_orders as lo', 'lo.refraction_id', '=', 'r.id')
            ->whereNull('lo.id')
            ->select('r.id', 'r.user_id', 'r.created_at')
            ->get()
            ->toArray();

        if (empty($refractions)) return;

        $frameProduct = DB::table('products')->where('name', 'LIKE', '%Frame%')->first();
        $lensProduct  = DB::table('products')->where('name', 'LIKE', '%Single Vision%')->first();
        if (!$frameProduct || !$lensProduct) return;

        $statuses = ['pending','pending','ready','collected','collected'];
        $rows     = [];
        $num      = 1;

        foreach ($refractions as $ref) {
            $dt         = $ref->created_at;
            $status     = $statuses[array_rand($statuses)];
            $framePrice = rand(80, 520);
            $lensPrice  = rand(110, 340);
            $pickUp     = Carbon::parse($dt)->addDays(rand(5, 21))->format('Y-m-d');

            $rows[] = [
                'user_id'                  => $ref->user_id,
                'refraction_id'            => $ref->id,
                'order_id'                 => 'LO' . str_pad($num++, 6, '0', STR_PAD_LEFT),
                'frame_product_id'         => $frameProduct->id,
                'lens_product_id'          => $lensProduct->id,
                'frame_model_number'       => 'FRM-' . strtoupper(Str::random(6)),
                'frame_price'              => $framePrice,
                'lens_price'               => $lensPrice,
                'pickUpDate'               => $pickUp,
                'status'                   => $status,
                'paid_amount'              => $status === 'collected' ? ($framePrice + $lensPrice) : 0,
                'collected_at'             => $status === 'collected' ? ($pickUp . ' 14:00:00') : null,
                'renewal_date'             => Carbon::parse($pickUp)->addYear()->format('Y-m-d'),
                'renewal_approval_status'  => 'pending',
                'notes'                    => null,
                'created_at'               => $dt,
                'updated_at'               => $dt,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('lens_orders')->insert($chunk);
        }

        $this->note(DB::table('lens_orders')->count() . ' lens orders total.');
    }

    // =========================================================================
    // APPOINTMENTS
    // =========================================================================

    private function seedAppointments(): void
    {
        $target   = 350;
        $existing = DB::table('appointments')->count();

        if ($existing >= $target) {
            $this->note("Appointments already at {$existing}, skipping.");
            return;
        }

        $toSeed       = $target - $existing;
        $patientCount = count($this->patientIds);
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;
        $futureTs     = $this->end->copy()->addDays(90)->timestamp;

        $rows = [];
        for ($i = 0; $i < $toSeed; $i++) {
            $r = rand(1, 100);

            if ($r <= 65) {
                $scheduledAt = Carbon::createFromTimestamp(rand($startTs, $endTs));
                $status      = 'completed';
            } elseif ($r <= 75) {
                $scheduledAt = Carbon::createFromTimestamp(rand($startTs, $endTs));
                $status      = 'cancelled';
            } elseif ($r <= 82) {
                $scheduledAt = Carbon::createFromTimestamp(rand($startTs, $endTs));
                $status      = 'missed';
            } else {
                $scheduledAt = Carbon::createFromTimestamp(rand($endTs, $futureTs));
                $status      = 'scheduled';
            }

            $createdAt = $scheduledAt->copy()->subDays(rand(1, 21));
            if ($createdAt->lt($this->start)) $createdAt = $this->start->copy();

            $rows[] = [
                'patient_id'   => $this->patientIds[rand(0, $patientCount - 1)],
                'user_id'      => $this->userIds[array_rand($this->userIds)],
                'title'        => self::APPT_TITLES[array_rand(self::APPT_TITLES)],
                'notes'        => null,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'status'       => $status,
                'created_at'   => $createdAt->format('Y-m-d H:i:s'),
                'updated_at'   => $createdAt->format('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('appointments')->insert($chunk);
        }

        $this->note(DB::table('appointments')->count() . ' appointments total.');
    }

    // =========================================================================
    // REFERRALS
    // =========================================================================

    private function seedReferrals(): void
    {
        $target   = 150;
        $existing = DB::table('referrals')->count();

        if ($existing >= $target) {
            $this->note("Referrals already at {$existing}, skipping.");
            return;
        }

        $toSeed       = $target - $existing;
        $patientCount = count($this->patientIds);
        $patients     = DB::table('patients')->select('id','name','contact','dob','gender')->get()->keyBy('id');
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;

        $specialists = [
            'Regional Eye Centre, Accra',
            'Korle Bu Teaching Hospital Eye Unit',
            'Komfo Anokye Teaching Hospital Eye Clinic',
            'Eye Foundation Hospital, Accra',
            'Ghana Eye Institute, Accra',
            '37 Military Hospital Eye Clinic',
            'University of Ghana Medical Centre',
            'Manhyia Hospital Eye Unit, Kumasi',
        ];
        $diagnoses = [
            'Suspected glaucoma','Open angle glaucoma','Cataract','Diabetic retinopathy',
            'Retinal detachment','Uveitis','Corneal ulcer','Pterygium','Dry eye syndrome',
            'Ocular hypertension','Keratoconus','Amblyopia','Age-related macular degeneration',
        ];
        $reasons = [
            'Further evaluation and specialist management',
            'Surgical management required',
            'Second opinion requested',
            'Specialist retinal assessment',
            'Paediatric ophthalmology review',
            'Glaucoma specialist management',
            'Corneal specialist review',
            'Neuro-ophthalmic evaluation',
        ];

        $rows = [];
        for ($i = 0; $i < $toSeed; $i++) {
            $date = Carbon::createFromTimestamp(rand($startTs, $endTs))->format('Y-m-d');
            $r    = rand(1, 100);
            $pid  = $this->patientIds[rand(0, $patientCount - 1)];
            $p    = $patients[$pid] ?? null;

            $rows[] = [
                'referred_by'         => $this->userIds[array_rand($this->userIds)],
                'patient_id'          => $pid,
                'referral_to'         => $specialists[array_rand($specialists)],
                'referral_date'       => $date,
                'patient_name'        => $p?->name ?? 'Unknown',
                'patient_age_sex'     => ($p ? Carbon::parse($p->dob)->age : rand(10, 80)) . ' yrs / ' . (($p?->gender === 'Male') ? 'M' : 'F'),
                'patient_contact'     => $p?->contact,
                'complaint'           => self::COMPLAINTS[array_rand(self::COMPLAINTS)],
                'va_od'               => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                'va_os'               => self::VA_OPTIONS[array_rand(self::VA_OPTIONS)],
                'refraction'          => null,
                'anterior_segment'    => 'See attached examination findings.',
                'posterior_segment'   => null,
                'iop'                 => rand(10, 24) . '/' . rand(10, 24) . ' mmHg',
                'diagnosis'           => $diagnoses[array_rand($diagnoses)],
                'reason_for_referral' => $reasons[array_rand($reasons)],
                'management'          => null,
                'status'              => $r <= 60 ? 'completed' : ($r <= 87 ? 'pending' : 'cancelled'),
                'letter_type'         => rand(0, 1) ? 'referral' : 'report',
                'created_at'          => $date . ' 10:00:00',
                'updated_at'          => $date . ' 10:00:00',
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('referrals')->insert($chunk);
        }

        $this->note(DB::table('referrals')->count() . ' referrals total.');
    }

    // =========================================================================
    // SALES + SALE ITEMS + PAYMENT TRANSACTIONS
    // =========================================================================

    private function seedSales(): void
    {
        $target   = 500;
        $existing = DB::table('sales')->count();

        if ($existing >= $target) {
            $this->note("Sales already at {$existing}, skipping.");
            return;
        }

        $toSeed = $target - $existing;

        $consultations = DB::table('consultations')
            ->select('id', 'patient_id', 'user_id')
            ->get()
            ->toArray();

        if (empty($consultations)) {
            $this->note('No consultations found; skipping sales.');
            return;
        }

        $totalConst   = count($consultations);
        $productArr   = array_values($this->productMap);
        $productCount = count($productArr);
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;
        $txnBase      = $existing;

        $bar = $this->command->getOutput()->createProgressBar($toSeed);
        $bar->start();

        $seeded = 0;
        while ($seeded < $toSeed) {
            $count       = min(200, $toSeed - $seeded);
            $salesInsert = [];
            $meta        = [];

            for ($i = 0; $i < $count; $i++) {
                $global     = $txnBase + $seeded + $i + 1;
                $txnId      = 'TXN' . str_pad($global, 9, '0', STR_PAD_LEFT);
                $consult    = $consultations[($seeded + $i) % $totalConst];
                $date       = Carbon::createFromTimestamp(rand($startTs, $endTs))->format('Y-m-d H:i:s');
                $numItems   = rand(1, min(4, $productCount));
                $itemKeys   = (array) array_rand($productArr, $numItems);
                $total      = 0;
                $profit     = 0;
                $lineItems  = [];

                foreach ($itemKeys as $key) {
                    $qty     = rand(1, 3);
                    $price   = $productArr[$key]['sell'];
                    $total  += $price * $qty;
                    $profit += ($price - $productArr[$key]['cost']) * $qty;
                    $lineItems[] = ['key' => $key, 'qty' => $qty];
                }

                $r          = rand(1, 100);
                $status     = $r <= 75 ? 'paid' : ($r <= 92 ? 'partial' : 'unpaid');
                $amountPaid = match ($status) {
                    'paid'    => $total,
                    'partial' => round($total * (rand(30, 80) / 100), 2),
                    default   => 0.00,
                };

                // ~5% get a discount
                $discount = rand(1, 100) <= 5 ? round($total * (rand(5, 15) / 100), 2) : 0;
                if ($discount > 0) {
                    $total  -= $discount;
                }

                $salesInsert[] = [
                    'user_id'         => $consult->user_id,
                    'patient_id'      => $consult->patient_id,
                    'consultation_id' => $consult->id,
                    'transaction_id'  => $txnId,
                    'total_amount'    => $total,
                    'amount_paid'     => $amountPaid,
                    'payment_status'  => $status,
                    'profit'          => $profit,
                    'discount_amount' => $discount,
                    'is_refunded'     => false,
                    'created_at'      => $date,
                    'updated_at'      => $date,
                ];
                $meta[$txnId] = ['items' => $lineItems, 'date' => $date, 'amount_paid' => $amountPaid];
            }

            DB::transaction(function () use ($salesInsert, $meta, $productArr) {
                DB::table('sales')->insert($salesInsert);

                $txnIds  = array_column($salesInsert, 'transaction_id');
                $saleMap = DB::table('sales')
                    ->whereIn('transaction_id', $txnIds)
                    ->pluck('id', 'transaction_id')
                    ->toArray();

                $itemRows    = [];
                $paymentRows = [];

                foreach ($salesInsert as $row) {
                    $saleId = $saleMap[$row['transaction_id']] ?? null;
                    if (!$saleId) continue;

                    $m = $meta[$row['transaction_id']];

                    foreach ($m['items'] as $li) {
                        $p = $productArr[$li['key']];
                        $itemRows[] = [
                            'sale_id'             => $saleId,
                            'product_id'          => $p['id'],
                            'prescribed_quantity' => $li['qty'],
                            'dispensed_quantity'  => $li['qty'],
                            'selling_price'       => $p['sell'],
                            'subtotal'            => $p['sell'] * $li['qty'],
                            'frequency'           => self::FREQUENCIES[array_rand(self::FREQUENCIES)],
                            'eye'                 => self::EYES[array_rand(self::EYES)],
                            'created_at'          => $m['date'],
                            'updated_at'          => $m['date'],
                        ];
                    }

                    if ($m['amount_paid'] > 0) {
                        $paymentRows[] = [
                            'sale_id'        => $saleId,
                            'amount'         => $m['amount_paid'],
                            'payment_method' => self::PAYMENT_METHODS[array_rand(self::PAYMENT_METHODS)],
                            'collected_by'   => $this->userIds[array_rand($this->userIds)],
                            'created_at'     => $m['date'],
                            'updated_at'     => $m['date'],
                        ];
                    }
                }

                if ($itemRows)    DB::table('sale_items')->insert($itemRows);
                if ($paymentRows) DB::table('payment_transactions')->insert($paymentRows);
            }, 3);

            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->note(
            DB::table('sales')->count() . ' sales, '
            . DB::table('sale_items')->count() . ' sale items, '
            . DB::table('payment_transactions')->count() . ' payments.'
        );
    }

    // =========================================================================
    // EXPENSES
    // =========================================================================

    private function seedExpenses(): void
    {
        if (DB::table('expenses')->count() > 0) {
            $this->note('Expenses already seeded, skipping.');
            return;
        }

        $catMap = DB::table('expense_categories')->pluck('id', 'name')->toArray();
        if (empty($catMap)) {
            $this->note('No expense categories found (run migrations). Skipping expenses.');
            return;
        }

        // [category, description, min, max, monthly (bool)]
        $templates = [
            ['Staff Salaries',   'Staff payroll',                    18000, 30000, true ],
            ['Rent / Utilities', 'Monthly office rent',               2500,  4000, true ],
            ['Rent / Utilities', 'Electricity bill',                   800,  1500, true ],
            ['Rent / Utilities', 'Water & sanitation bill',            150,   300, true ],
            ['Rent / Utilities', 'Internet & telephone',               400,   700, true ],
            ['Supplies',         'Ophthalmic consumables',             500,  2000, true ],
            ['Supplies',         'Office stationery & printing',       200,   600, true ],
            ['Supplies',         'Cleaning supplies',                  100,   300, true ],
            ['Equipment',        'Equipment servicing & calibration',  300,  5000, false],
            ['Maintenance',      'Building maintenance',               200,  2000, false],
            ['Marketing',        'Social media & online advertising',  500,  2000, true ],
            ['Bank Charges',     'Bank service charges',               100,   500, true ],
            ['Miscellaneous',    'Petty cash & sundries',              100,   500, true ],
            ['Miscellaneous',    'Transport & logistics',              200,   800, true ],
        ];

        $rows    = [];
        $current = $this->start->copy()->startOfMonth();

        while ($current->lte($this->end)) {
            $monthLabel = $current->format('M Y');

            foreach ($templates as [$catName, $desc, $min, $max, $monthly]) {
                $catId       = $catMap[$catName] ?? null;
                if (!$catId) continue;
                $occurrences = $monthly ? 1 : (rand(1, 10) <= 4 ? 1 : 0);

                for ($j = 0; $j < $occurrences; $j++) {
                    $day  = rand(1, min($current->daysInMonth, 28));
                    $date = $current->copy()->setDay($day)->format('Y-m-d');

                    $rows[] = [
                        'expense_category_id' => $catId,
                        'expense_date'        => $date,
                        'description'         => $monthly ? "$desc — $monthLabel" : $desc,
                        'amount'              => round(rand($min, $max) * (rand(93, 115) / 100), 2),
                        'reference'           => rand(1, 5) === 1 ? 'REF-' . strtoupper(substr(md5((string) rand()), 0, 8)) : null,
                        'notes'               => null,
                        'recorded_by'         => $this->userIds[array_rand($this->userIds)],
                        'created_at'          => $date . ' 10:00:00',
                        'updated_at'          => $date . ' 10:00:00',
                    ];
                }
            }
            $current->addMonth();
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('expenses')->insert($chunk);
        }

        $this->note(DB::table('expenses')->count() . ' expense records total.');
    }

    // =========================================================================
    // INCOME STATEMENT ENTRIES
    // =========================================================================

    private function seedIncomeStatementEntries(): void
    {
        if (DB::table('income_statement_entries')->count() > 0) {
            $this->note('IS entries already seeded, skipping.');
            return;
        }

        $lineItems = [
            ['Revenue',            'Consultation Fees',  15000],
            ['Revenue',            'Optical Sales',      28000],
            ['Revenue',            'Contact Lens Sales',  8000],
            ['Revenue',            'Other Services',      5000],
            ['Cost of Goods Sold', 'Cost of Frames',      8500],
            ['Cost of Goods Sold', 'Cost of Lenses',      6000],
            ['Cost of Goods Sold', 'Cost of Eye Drops',   2000],
            ['Operating Expenses', 'Staff Salaries',     18000],
            ['Operating Expenses', 'Rent',                2800],
            ['Operating Expenses', 'Utilities',           1500],
            ['Operating Expenses', 'Marketing',            600],
            ['Operating Expenses', 'Miscellaneous',        400],
        ];

        $rows    = [];
        $current = $this->start->copy()->startOfMonth();

        while ($current->lte($this->end)) {
            $entryDate = $current->copy()->endOfMonth()->format('Y-m-d');
            $seasonal  = in_array($current->month, [1, 4, 8, 12]) ? 1.12 : 1.0;

            foreach ($lineItems as [$section, $name, $base]) {
                $rows[] = [
                    'section'    => $section,
                    'name'       => $name,
                    'amount'     => round($base * $seasonal * (rand(88, 115) / 100), 2),
                    'percentage' => null,
                    'entry_date' => $entryDate,
                    'notes'      => null,
                    'is_active'  => true,
                    'created_at' => $entryDate . ' 09:00:00',
                    'updated_at' => $entryDate . ' 09:00:00',
                ];
            }
            $current->addMonth();
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('income_statement_entries')->insert($chunk);
        }

        $this->note(DB::table('income_statement_entries')->count() . ' income statement entries.');
    }

    // =========================================================================
    // QUOTATIONS
    // =========================================================================

    private function seedQuotations(): void
    {
        $target   = 80;
        $existing = DB::table('quotations')->count();

        if ($existing >= $target) {
            $this->note("Quotations already at {$existing}, skipping.");
            return;
        }

        $toSeed       = $target - $existing;
        $products     = DB::table('products')->select('id', 'name', 'selling_price')->get()->toArray();
        $patientCount = count($this->patientIds);
        $productCount = count($products);
        $patients     = DB::table('patients')->select('id', 'name', 'contact')->get()->keyBy('id');
        $maxNum       = (int) DB::table('quotations')
            ->selectRaw('MAX(CAST(SUBSTRING(quotation_number, 3) AS UNSIGNED)) as m')
            ->value('m');
        $startTs = $this->start->timestamp;
        $endTs   = $this->end->timestamp;

        $statuses    = ['accepted','accepted','accepted','sent','sent','expired','draft','draft','cancelled'];
        $quotRows    = [];
        $itemBatches = [];

        for ($i = 0; $i < $toSeed; $i++) {
            $issueDate  = Carbon::createFromTimestamp(rand($startTs, $endTs));
            $validUntil = $issueDate->copy()->addDays(30);
            $status     = $statuses[array_rand($statuses)];
            $pid        = $this->patientIds[rand(0, $patientCount - 1)];
            $p          = $patients[$pid] ?? null;

            $numItems  = rand(2, 4);
            $itemKeys  = (array) array_rand(range(0, $productCount - 1), min($numItems, $productCount));
            $subtotal  = 0;
            $lineItems = [];

            foreach ($itemKeys as $key) {
                $product = $products[$key];
                $qty     = rand(1, 3);
                $price   = $product->selling_price;
                $lineSub = $qty * $price;
                $subtotal += $lineSub;
                $lineItems[] = [
                    'product_id'  => $product->id,
                    'description' => $product->name,
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'subtotal'    => $lineSub,
                ];
            }

            $discount = rand(0, 4) === 0 ? round($subtotal * (rand(5, 15) / 100), 2) : 0;
            $total    = $subtotal - $discount;
            $qtNum    = 'QT' . str_pad($maxNum + $existing + $i + 1, 6, '0', STR_PAD_LEFT);

            $quotRows[] = [
                'quotation_number' => $qtNum,
                'patient_id'       => $pid,
                'patient_name'     => $p?->name ?? 'Walk-in Client',
                'patient_phone'    => $p?->contact ?? ('+23320' . rand(1000000, 9999999)),
                'status'           => $status,
                'issue_date'       => $issueDate->format('Y-m-d'),
                'valid_until'      => $validUntil->format('Y-m-d'),
                'notes'            => null,
                'subtotal'         => $subtotal,
                'discount_amount'  => $discount,
                'total_amount'     => $total,
                'created_by'       => $this->userIds[array_rand($this->userIds)],
                'created_at'       => $issueDate->format('Y-m-d H:i:s'),
                'updated_at'       => $issueDate->format('Y-m-d H:i:s'),
            ];
            $itemBatches[] = ['qt_number' => $qtNum, 'items' => $lineItems, 'date' => $issueDate->format('Y-m-d H:i:s')];
        }

        DB::table('quotations')->insert($quotRows);

        $qtMap = DB::table('quotations')
            ->whereIn('quotation_number', array_column($quotRows, 'quotation_number'))
            ->pluck('id', 'quotation_number')
            ->toArray();

        $itemRows = [];
        foreach ($itemBatches as $batch) {
            $qtId = $qtMap[$batch['qt_number']] ?? null;
            if (!$qtId) continue;
            foreach ($batch['items'] as $item) {
                $item['quotation_id'] = $qtId;
                $item['created_at']   = $batch['date'];
                $item['updated_at']   = $batch['date'];
                $itemRows[]           = $item;
            }
        }
        if ($itemRows) DB::table('quotation_items')->insert($itemRows);

        $this->note(DB::table('quotations')->count() . ' quotations, ' . DB::table('quotation_items')->count() . ' items.');
    }

    // =========================================================================
    // PURCHASE ORDERS + ITEMS + STOCK MOVEMENTS
    // =========================================================================

    private function seedPurchaseOrders(): void
    {
        if (DB::table('purchase_orders')->count() > 0) {
            $this->note('Purchase orders already seeded, skipping.');
            return;
        }

        $supplierIds  = DB::table('suppliers')->pluck('id')->toArray();
        $products     = DB::table('products')
            ->whereNotIn('name', ['Eye Examination','Contact Lens Fitting','Frame Adjustment','Lens Replacement'])
            ->select('id', 'name', 'cost_price', 'quantity')
            ->get()
            ->toArray();

        if (empty($supplierIds) || empty($products)) return;

        $productCount = count($products);
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;
        $adminId      = $this->userIds[0];

        $poRows      = [];
        $itemBatches = [];
        $poStatuses  = ['received','received','received','sent','draft','cancelled'];

        for ($i = 0; $i < 30; $i++) {
            $orderDate    = Carbon::createFromTimestamp(rand($startTs, $endTs));
            $expectedDate = $orderDate->copy()->addDays(rand(7, 30));
            $status       = $poStatuses[array_rand($poStatuses)];
            $receivedAt   = $status === 'received' ? $expectedDate->copy()->addDays(rand(0, 5))->format('Y-m-d H:i:s') : null;

            $numItems  = rand(3, 7);
            $itemKeys  = (array) array_rand(range(0, $productCount - 1), min($numItems, $productCount));
            $total     = 0;
            $lineItems = [];

            foreach ($itemKeys as $key) {
                $product = $products[$key];
                $qty     = rand(10, 100);
                $cost    = $product->cost_price;
                $sub     = $qty * $cost;
                $total  += $sub;
                $qtyRec  = $status === 'received' ? $qty : 0;

                $lineItems[] = [
                    'product_id'        => $product->id,
                    'description'       => $product->name,
                    'quantity_ordered'  => $qty,
                    'quantity_received' => $qtyRec,
                    'unit_cost'         => $cost,
                    'subtotal'          => $sub,
                    'batch_number'      => 'BATCH-' . strtoupper(Str::random(6)),
                    'manufacture_date'  => $orderDate->copy()->subMonths(rand(1, 6))->format('Y-m-d'),
                    'expiry_date'       => $orderDate->copy()->addMonths(rand(12, 36))->format('Y-m-d'),
                    '_qty_before'       => $product->quantity,
                ];
            }

            $poNum    = 'PO' . str_pad($i + 1, 6, '0', STR_PAD_LEFT);
            $poRows[] = [
                'po_number'     => $poNum,
                'supplier_id'   => $supplierIds[array_rand($supplierIds)],
                'status'        => $status,
                'order_date'    => $orderDate->format('Y-m-d'),
                'expected_date' => $expectedDate->format('Y-m-d'),
                'notes'         => null,
                'total_amount'  => $total,
                'created_by'    => $adminId,
                'received_by'   => $status === 'received' ? $adminId : null,
                'received_at'   => $receivedAt,
                'created_at'    => $orderDate->format('Y-m-d H:i:s'),
                'updated_at'    => $orderDate->format('Y-m-d H:i:s'),
            ];
            $itemBatches[] = ['po_number' => $poNum, 'items' => $lineItems, 'date' => $orderDate->format('Y-m-d H:i:s')];
        }

        DB::table('purchase_orders')->insert($poRows);

        $poMap = DB::table('purchase_orders')
            ->whereIn('po_number', array_column($poRows, 'po_number'))
            ->pluck('id', 'po_number')
            ->toArray();

        $itemRows       = [];
        $stockMovements = [];
        $movNum         = 1;

        foreach ($itemBatches as $batch) {
            $poId = $poMap[$batch['po_number']] ?? null;
            if (!$poId) continue;

            foreach ($batch['items'] as $item) {
                $qtyBefore = $item['_qty_before'] ?? 0;
                unset($item['_qty_before']);

                $item['purchase_order_id'] = $poId;
                $item['created_at']        = $batch['date'];
                $item['updated_at']        = $batch['date'];
                $itemRows[]                = $item;

                if ($item['quantity_received'] > 0) {
                    $stockMovements[] = [
                        'product_id'      => $item['product_id'],
                        'user_id'         => $adminId,
                        'reference_no'    => 'SM-' . str_pad($movNum++, 6, '0', STR_PAD_LEFT),
                        'movement_type'   => 'stock_in',
                        'supplier'        => 'PO: ' . $batch['po_number'],
                        'batch_number'    => $item['batch_number'],
                        'quantity_before' => $qtyBefore,
                        'quantity_after'  => $qtyBefore + $item['quantity_received'],
                        'quantity'        => $item['quantity_received'],
                        'cost_price'      => $item['unit_cost'],
                        'manufacture_date'=> $item['manufacture_date'],
                        'expiry_date'     => $item['expiry_date'],
                        'notes'           => 'Received — ' . $batch['po_number'],
                        'created_at'      => $batch['date'],
                        'updated_at'      => $batch['date'],
                    ];
                }
            }
        }

        if ($itemRows)       DB::table('purchase_order_items')->insert($itemRows);
        if ($stockMovements) DB::table('stock_movements')->insert($stockMovements);

        $this->note(
            DB::table('purchase_orders')->count() . ' POs, '
            . DB::table('purchase_order_items')->count() . ' PO items, '
            . DB::table('stock_movements')->count() . ' stock movements.'
        );
    }

    // =========================================================================
    // DISCOUNT APPROVAL REQUESTS
    // =========================================================================

    private function seedDiscountApprovals(): void
    {
        if (DB::table('discount_approval_requests')->count() > 0) {
            $this->note('Discount approvals already seeded, skipping.');
            return;
        }

        $patientCount = count($this->patientIds);
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;
        $statuses     = ['approved','approved','approved','pending','pending','rejected'];
        $types        = ['percentage','fixed'];

        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $dt          = Carbon::createFromTimestamp(rand($startTs, $endTs));
            $date        = $dt->format('Y-m-d H:i:s');
            $status      = $statuses[array_rand($statuses)];
            $cashierId   = $this->userIds[array_rand($this->userIds)];
            $grossAmount = rand(100, 900) * 1.0;
            $type        = $types[array_rand($types)];
            $value       = $type === 'percentage' ? rand(5, 20) : rand(20, 100);
            $discAmt     = $type === 'percentage'
                ? round($grossAmount * $value / 100, 2)
                : (float) min($value, $grossAmount * 0.3);
            $finalAmount = $grossAmount - $discAmt;

            $approvedBy = null;
            $approvedAt = null;
            $rejectedBy = null;
            $rejectedAt = null;

            if ($status === 'approved') {
                $approvedBy = $this->userIds[array_rand($this->userIds)];
                $approvedAt = $dt->copy()->addMinutes(rand(5, 60))->format('Y-m-d H:i:s');
            } elseif ($status === 'rejected') {
                $rejectedBy = $this->userIds[array_rand($this->userIds)];
                $rejectedAt = $dt->copy()->addMinutes(rand(5, 60))->format('Y-m-d H:i:s');
            }

            $rows[] = [
                'cashier_id'      => $cashierId,
                'patient_id'      => $this->patientIds[rand(0, $patientCount - 1)],
                'discount_type'   => $type,
                'discount_value'  => $value,
                'discount_amount' => $discAmt,
                'gross_amount'    => $grossAmount,
                'final_amount'    => $finalAmount,
                'cart_snapshot'   => json_encode([]),
                'status'          => $status,
                'approved_by'     => $approvedBy,
                'approved_at'     => $approvedAt,
                'rejected_by'     => $rejectedBy,
                'rejected_at'     => $rejectedAt,
                'notes'           => null,
                'created_at'      => $date,
                'updated_at'      => $date,
            ];
        }

        DB::table('discount_approval_requests')->insert($rows);
        $this->note(DB::table('discount_approval_requests')->count() . ' discount approval requests.');
    }

    // =========================================================================
    // REFUND LOGS
    // =========================================================================

    private function seedRefundLogs(): void
    {
        if (DB::table('refund_logs')->count() > 0) {
            $this->note('Refund logs already seeded, skipping.');
            return;
        }

        $saleIds = DB::table('sales')->pluck('id')->toArray();
        if (empty($saleIds)) return;

        $startTs  = $this->start->timestamp;
        $endTs    = $this->end->timestamp;
        $statuses = ['processed','processed','approved','pending','pending','rejected'];

        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $dt          = Carbon::createFromTimestamp(rand($startTs, $endTs));
            $date        = $dt->format('Y-m-d H:i:s');
            $status      = $statuses[array_rand($statuses)];
            $initiatorId = $this->userIds[array_rand($this->userIds)];
            $actorId     = $this->userIds[array_rand($this->userIds)];

            $approvedBy  = in_array($status, ['approved','processed']) ? $actorId : null;
            $approvedAt  = $approvedBy ? $dt->copy()->addMinutes(rand(10, 120))->format('Y-m-d H:i:s') : null;
            $processedBy = $status === 'processed' ? $actorId : null;
            $processedAt = $processedBy ? $dt->copy()->addMinutes(rand(120, 360))->format('Y-m-d H:i:s') : null;
            $rejectedBy  = $status === 'rejected' ? $actorId : null;
            $rejectedAt  = $rejectedBy ? $dt->copy()->addMinutes(rand(10, 120))->format('Y-m-d H:i:s') : null;

            $rows[] = [
                'sale_id'          => $saleIds[array_rand($saleIds)],
                'status'           => $status,
                'initiated_by'     => $initiatorId,
                'approved_by'      => $approvedBy,
                'processed_by'     => $processedBy,
                'rejected_by'      => $rejectedBy,
                'reason'           => 'Customer requested refund — incorrect product dispensed.',
                'rejection_reason' => $rejectedBy ? 'Refund request outside policy window.' : null,
                'initiated_at'     => $date,
                'approved_at'      => $approvedAt,
                'processed_at'     => $processedAt,
                'rejected_at'      => $rejectedAt,
                'created_at'       => $date,
                'updated_at'       => $date,
            ];
        }

        DB::table('refund_logs')->insert($rows);
        $this->note(DB::table('refund_logs')->count() . ' refund log entries.');
    }

    // =========================================================================
    // LOGIN LOGS
    // =========================================================================

    private function seedLoginLogs(): void
    {
        if (DB::table('login_logs')->count() >= 100) {
            $this->note('Login logs already at target, skipping.');
            return;
        }

        $startTs  = $this->start->timestamp;
        $endTs    = $this->end->timestamp;
        $browsers = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
        ];

        $rows = [];
        foreach ($this->userIds as $uid) {
            for ($i = 0; $i < rand(20, 40); $i++) {
                $rows[] = [
                    'user_id'    => $uid,
                    'ip_address' => '192.168.' . rand(1, 10) . '.' . rand(2, 254),
                    'user_agent' => $browsers[array_rand($browsers)],
                    'login_at'   => Carbon::createFromTimestamp(rand($startTs, $endTs))->format('Y-m-d H:i:s'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('login_logs')->insert($rows);
        $this->note(DB::table('login_logs')->count() . ' login log entries.');
    }

    // =========================================================================
    // SMS LOGS
    // =========================================================================

    private function seedSmsLogs(): void
    {
        if (DB::table('sms_logs')->count() > 0) {
            $this->note('SMS logs already seeded, skipping.');
            return;
        }

        $patientCount = count($this->patientIds);
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;
        $templateKeys = ['appointment_reminder','birthday_wishes','recall_reminder','spectacle_renewal','custom_broadcast'];
        $channels     = ['sms','whatsapp'];

        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $pid     = $this->patientIds[rand(0, $patientCount - 1)];
            $patient = DB::table('patients')->where('id', $pid)->first();
            $key     = $templateKeys[array_rand($templateKeys)];
            $date    = Carbon::createFromTimestamp(rand($startTs, $endTs))->format('Y-m-d H:i:s');
            $success = rand(1, 10) > 1;

            $rows[] = [
                'patient_id'   => $pid,
                'template_key' => $key,
                'channel'      => $channels[array_rand($channels)],
                'recipient'    => $patient->contact ?? ('+23320' . rand(1000000, 9999999)),
                'message'      => "Dear {$patient->name}, this is a message from VisionCare Eye Clinic.",
                'success'      => $success,
                'error'        => $success ? null : 'Failed to deliver — recipient unreachable.',
                'created_at'   => $date,
                'updated_at'   => $date,
            ];
        }

        DB::table('sms_logs')->insert($rows);
        $this->note(DB::table('sms_logs')->count() . ' SMS log entries.');
    }

    // =========================================================================
    // APP NOTIFICATIONS
    // =========================================================================

    private function seedAppNotifications(): void
    {
        if (DB::table('app_notifications')->count() > 0) {
            $this->note('Notifications already seeded, skipping.');
            return;
        }

        $startTs = $this->start->timestamp;
        $endTs   = $this->end->timestamp;
        $types   = ['info','warning','success','alert'];
        $titles  = [
            'New patient registered',
            'Appointment reminder',
            'Low stock alert — reorder needed',
            'Refund request pending approval',
            'Clearance revoke request',
            'Monthly sales report ready',
            'Discount approval request',
            'Lens order ready for collection',
            'License renewal reminder',
            'New referral letter created',
        ];

        $rows = [];
        foreach ($this->userIds as $uid) {
            for ($i = 0; $i < rand(8, 15); $i++) {
                $date    = Carbon::createFromTimestamp(rand($startTs, $endTs))->format('Y-m-d H:i:s');
                $isRead  = rand(0, 1);
                $rows[]  = [
                    'user_id'    => $uid,
                    'type'       => $types[array_rand($types)],
                    'title'      => $titles[array_rand($titles)],
                    'body'       => 'This is an automated system notification from VisionCare Eye Clinic.',
                    'icon'       => 'bell',
                    'icon_color' => ['blue','green','yellow','red'][array_rand(['blue','green','yellow','red'])],
                    'action_url' => null,
                    'data'       => json_encode([]),
                    'read_at'    => $isRead ? $date : null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
        }

        DB::table('app_notifications')->insert($rows);
        $this->note(DB::table('app_notifications')->count() . ' in-app notifications.');
    }

    // =========================================================================
    // STAFF MESSAGES
    // =========================================================================

    private function seedStaffMessages(): void
    {
        if (DB::table('staff_messages')->count() > 0) {
            $this->note('Staff messages already seeded, skipping.');
            return;
        }

        $userCount = count($this->userIds);
        if ($userCount < 2) return;

        $startTs  = $this->start->timestamp;
        $endTs    = $this->end->timestamp;
        $subjects = [
            'Urgent: Patient follow-up needed',
            'Stock replenishment required',
            'Monthly report reminder',
            'System maintenance scheduled for weekend',
            'New appointment protocol update',
            'Payment discrepancy — please review',
            'Referral letter completed for patient',
            'Lens order ready for collection',
            'Staff meeting rescheduled',
            'New SOP for clinical records',
            'Holiday cover schedule',
            'Training on new POS features',
        ];

        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $senderIdx    = rand(0, $userCount - 1);
            $recipientIdx = ($senderIdx + 1 + rand(0, $userCount - 2)) % $userCount;
            $dt           = Carbon::createFromTimestamp(rand($startTs, $endTs));
            $date         = $dt->format('Y-m-d H:i:s');

            $rows[] = [
                'sender_id'    => $this->userIds[$senderIdx],
                'recipient_id' => $this->userIds[$recipientIdx],
                'subject'      => $subjects[array_rand($subjects)],
                'body'         => 'Please review and action at your earliest convenience. Thank you.',
                'read_at'      => rand(0, 1) ? $date : null,
                'parent_id'    => null,
                'created_at'   => $date,
                'updated_at'   => $date,
            ];

            // 40% chance of a reply
            if (rand(1, 10) <= 4 && $i > 0) {
                $replyDate = $dt->copy()->addHours(rand(1, 48))->format('Y-m-d H:i:s');
                $rows[] = [
                    'sender_id'    => $this->userIds[$recipientIdx],
                    'recipient_id' => $this->userIds[$senderIdx],
                    'subject'      => 'Re: ' . $subjects[array_rand($subjects)],
                    'body'         => 'Thank you for the update. I will action this promptly.',
                    'read_at'      => rand(0, 1) ? $replyDate : null,
                    'parent_id'    => null,
                    'created_at'   => $replyDate,
                    'updated_at'   => $replyDate,
                ];
            }
        }

        DB::table('staff_messages')->insert($rows);
        $this->note(DB::table('staff_messages')->count() . ' staff messages.');
    }

    // =========================================================================
    // AUDIT TRAIL
    // =========================================================================

    private function seedAuditTrail(): void
    {
        if (DB::table('audit_trails')->count() > 0) {
            $this->note('Audit trail already seeded, skipping.');
            return;
        }

        $patientCount = count($this->patientIds);
        $startTs      = $this->start->timestamp;
        $endTs        = $this->end->timestamp;

        $events = ['created','updated','viewed','deleted'];
        $descs  = [
            'Patient record created',
            'Patient contact information updated',
            'Consultation record viewed by doctor',
            'Sale transaction processed at POS',
            'Clearance status updated by cashier',
            'User account login recorded',
            'Monthly report generated',
            'Clinic settings updated',
            'Refund request initiated',
            'Appointment status changed',
            'Product stock updated after purchase order receipt',
            'Referral letter created and saved',
        ];
        $auditableTypes = [
            'App\\Models\\Patient',
            'App\\Models\\Consultations',
            'App\\Models\\Sales',
            'App\\Models\\CashierPatientClearance',
        ];
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0.0.0';

        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $pid  = $this->patientIds[rand(0, $patientCount - 1)];
            $type = $auditableTypes[array_rand($auditableTypes)];
            $date = Carbon::createFromTimestamp(rand($startTs, $endTs))->format('Y-m-d H:i:s');

            $rows[] = [
                'user_id'        => $this->userIds[array_rand($this->userIds)],
                'patient_id'     => rand(0, 3) > 0 ? $pid : null,
                'auditable_type' => $type,
                'auditable_id'   => $pid,
                'event'          => $events[array_rand($events)],
                'description'    => $descs[array_rand($descs)],
                'old_values'     => json_encode([]),
                'new_values'     => json_encode([]),
                'ip_address'     => '192.168.' . rand(1, 10) . '.' . rand(2, 254),
                'user_agent'     => $ua,
                'created_at'     => $date,
                'updated_at'     => $date,
            ];
        }

        DB::table('audit_trails')->insert($rows);
        $this->note(DB::table('audit_trails')->count() . ' audit trail entries.');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function step(string $msg): void
    {
        $this->command->info('');
        $this->command->info("  ▶ {$msg}");
    }

    private function note(string $msg): void
    {
        $this->command->line("    ✓ {$msg}");
    }

    private function printHeader(): void
    {
        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║      LIVE DEMO SEEDER — VisionCare Eye Clinic System       ║');
        $this->command->info('║      Data window : May 1, 2025 → May 28, 2026             ║');
        $this->command->info('║      PRO License : 2-year trial (all features unlocked)   ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');
        $this->command->info('');
    }

    private function printFooter(): void
    {
        $tables = [
            'users'                       => 'Users',
            'patients'                    => 'Patients',
            'cashier_patient_clearances'  => 'Clearances',
            'consultations'               => 'Consultations',
            'refractions'                 => 'Refractions',
            'consultation_diagnosis'      => 'Diagnosis links',
            'lens_orders'                 => 'Lens orders',
            'appointments'                => 'Appointments',
            'referrals'                   => 'Referrals',
            'sales'                       => 'Sales',
            'sale_items'                  => 'Sale items',
            'payment_transactions'        => 'Payments',
            'expenses'                    => 'Expenses',
            'income_statement_entries'    => 'IS entries',
            'quotations'                  => 'Quotations',
            'quotation_items'             => 'Quotation items',
            'purchase_orders'             => 'Purchase orders',
            'purchase_order_items'        => 'PO items',
            'stock_movements'             => 'Stock movements',
            'discount_approval_requests'  => 'Discount approvals',
            'refund_logs'                 => 'Refund logs',
            'login_logs'                  => 'Login logs',
            'sms_logs'                    => 'SMS logs',
            'app_notifications'           => 'Notifications',
            'staff_messages'              => 'Staff messages',
            'audit_trails'                => 'Audit trail',
        ];

        $licExpiry = Carbon::now()->addDays(730)->format('d M Y');

        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║                    SEEDING COMPLETE                        ║');
        $this->command->info('╠════════════════════════════════════════════════════════════╣');

        foreach ($tables as $table => $label) {
            try {
                $n = DB::table($table)->count();
                $this->command->info(sprintf('║  %-32s %12s  ║', $label . ':', number_format($n)));
            } catch (\Throwable) {
                // table may not exist in all environments
            }
        }

        $this->command->info('╠════════════════════════════════════════════════════════════╣');
        $this->command->info('║  PRO License   Trial active — expires ' . sprintf('%-22s', $licExpiry) . '║');
        $this->command->info('╠════════════════════════════════════════════════════════════╣');
        $this->command->info('║  Login credentials (password: "password" for all)          ║');
        $this->command->info('║  • frimkings@gmail.com          [Super Admin]              ║');
        $this->command->info('║  • dr.amankwah@eyeclinic.com    [Doctor]                   ║');
        $this->command->info('║  • secretary@eyeclinic.com      [Secretary]                ║');
        $this->command->info('║  • cashier@eyeclinic.com        [Cashier]                  ║');
        $this->command->info('║  • manager@eyeclinic.com        [Manager]                  ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');
        $this->command->info('');
    }
}
