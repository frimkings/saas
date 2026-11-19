<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * LOADTEST-20: fills a separate database with about a year of synthetic work for N clinics
 * (patients, visits, consultations, refractions, optical orders with their sales and payments,
 * clinic sales, stock, lens grids and expenses) so busy screens can be timed at realistic size.
 * Refuses to run unless the database name contains "loadtest".
 */
class LoadTestSeedCommand extends Command
{
    protected $signature = 'loadtest:seed {--clinics=20} {--days=365} {--password=}';
    protected $description = 'Seed synthetic clinics for load measurement (loadtest database only)';

    private int $days;
    private string $now;

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (! str_contains($db, 'loadtest')) {
            $this->error("Refusing: database [$db] is not a loadtest database.");
            return self::FAILURE;
        }
        $this->days = (int) $this->option('days');
        $this->now = now()->toDateTimeString();
        mt_srand(20);

        $plan = DB::table('subscription_plans')->where('code', 'test-all-features')->value('id')
            ?? DB::table('subscription_plans')->insertGetId(['code' => 'test-all-features', 'name' => 'Load test (all features)', 'features' => json_encode(['*']),
                'base_price' => 0, 'billing_interval' => 'monthly', 'created_at' => $this->now, 'updated_at' => $this->now]);
        $password = Hash::make((string) ($this->option('password') ?: 'loadtest-'.Str::random(8)));
        $roles = Role::pluck('id', 'name');

        $start = (int) DB::table('clinics')->where('slug', 'like', 'loadtest-%')->count();
        for ($n = $start + 1; $n <= $start + (int) $this->option('clinics'); $n++) {
            $t = microtime(true);
            DB::transaction(fn () => $this->clinic($n, $plan, $password, $roles));
            $this->info(sprintf('Clinic %d seeded in %.1fs', $n, microtime(true) - $t));
        }

        return self::SUCCESS;
    }

    private function clinic(int $n, int $plan, string $password, $roles): void
    {
        $ts = ['created_at' => $this->now, 'updated_at' => $this->now];
        $clinicId = DB::table('clinics')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => "Load Test Clinic $n", 'slug' => "loadtest-$n",
            'patient_prefix' => 'LT', 'status' => 'active', 'deployment_mode' => 'hosted', 'default_timezone' => 'Africa/Accra', 'default_currency' => 'GHS'] + $ts);
        $branchIds = [];
        foreach ($n % 4 === 0 ? ['MAIN', 'B2'] : ['MAIN'] as $i => $code) {
            $branchIds[] = DB::table('branches')->insertGetId(['uuid' => (string) Str::uuid(), 'clinic_id' => $clinicId, 'code' => $code,
                'name' => $i ? 'Second branch' : 'Main', 'is_default' => $i === 0, 'is_active' => true, 'timezone' => 'Africa/Accra'] + $ts);
        }
        DB::table('clinic_subscriptions')->insert(['clinic_id' => $clinicId, 'subscription_plan_id' => $plan, 'status' => 'active', 'billing_interval' => 'monthly',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addYear()] + $ts);
        DB::table('settings')->insert(['clinic_id' => $clinicId, 'clinic_name' => "Load Test Clinic $n", 'currency_symbol' => 'GHS'] + $ts);
        DB::table('optical_settings')->insert(['clinic_id' => $clinicId] + $ts);

        // Staff: one of each role per branch.
        $staff = [];
        foreach ($branchIds as $b => $branchId) {
            foreach (['Super Admin', 'Manager', 'Secretary', 'Cashier', 'Doctor', 'Optician'] as $role) {
                if ($b > 0 && $role === 'Super Admin') continue;
                $slug = Str::slug($role);
                $userId = DB::table('users')->insertGetId(['name' => "$role $n".($b ? "-$b" : ''), 'email' => "lt{$n}-{$slug}".($b ? "-$b" : '').'@loadtest.local',
                    'password' => $password, 'is_active' => true, 'email_verified_at' => $this->now] + $ts);
                DB::table('clinic_user')->insert(['clinic_id' => $clinicId, 'user_id' => $userId, 'status' => 'active', 'is_default' => true, 'joined_at' => $this->now] + $ts);
                foreach ($role === 'Super Admin' ? $branchIds : [$branchId] as $bid) {
                    DB::table('branch_user')->insert(['branch_id' => $bid, 'user_id' => $userId, 'status' => 'active', 'is_default' => $bid === $branchId] + $ts);
                    if (isset($roles[$role])) DB::table('branch_user_role')->insert(['branch_id' => $bid, 'user_id' => $userId, 'role_id' => $roles[$role]] + $ts);
                }
                if (isset($roles[$role])) DB::table('model_has_roles')->insertOrIgnore(['role_id' => $roles[$role], 'model_type' => 'App\\Models\\User', 'model_id' => $userId]);
                $staff[$branchId][$role] = $userId;
            }
        }

        // Catalogue: drugs and services, optical frames and lenses, lens grid.
        $catId = DB::table('categories')->insertGetId(['clinic_id' => $clinicId, 'user_id' => $staff[$branchIds[0]]['Manager'], 'name' => 'Eye drops', 'is_active' => true] + $ts);
        $products = [];
        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $cost = mt_rand(500, 15000) / 100;
            $rows[] = ['clinic_id' => $clinicId, 'user_id' => $staff[$branchIds[0]]['Manager'], 'name' => "Drug $i", 'category_id' => $catId, 'batch_number' => "B$n-$i",
                'quantity' => mt_rand(0, 400), 'cost_price' => $cost, 'selling_price' => round($cost * 1.5, 2), 'expiry_date' => now()->addDays(mt_rand(-30, 700))->toDateString()] + $ts;
        }
        DB::table('products')->insert($rows);
        $products = DB::table('products')->where('clinic_id', $clinicId)->pluck('selling_price', 'id')->all();

        $frameCat = DB::table('optical_categories')->insertGetId(['clinic_id' => $clinicId, 'code' => 'FRM', 'name' => 'Frames', 'type' => 'frame', 'is_active' => true] + $ts);
        $lensCat = DB::table('optical_categories')->insertGetId(['clinic_id' => $clinicId, 'code' => 'LNS', 'name' => 'Lenses', 'type' => 'lens', 'is_active' => true] + $ts);
        $rows = [];
        for ($i = 1; $i <= 220; $i++) {
            $frame = $i <= 160;
            $cost = $frame ? mt_rand(3000, 40000) / 100 : mt_rand(2000, 30000) / 100;
            $rows[] = ['clinic_id' => $clinicId, 'optical_category_id' => $frame ? $frameCat : $lensCat, 'type' => $frame ? 'frame' : 'lens', 'sku' => ($frame ? 'FR' : 'LN')."-$n-$i",
                'name' => ($frame ? 'Frame ' : 'Lens ').$i, 'brand' => ['Ray', 'Vogue', 'Essilor', 'Hoya'][$i % 4], 'cost_price' => $cost, 'selling_price' => round($cost * 2, 2), 'is_active' => true] + $ts;
        }
        DB::table('optical_products')->insert($rows);
        $optical = DB::table('optical_products')->where('clinic_id', $clinicId)->get(['id', 'type', 'selling_price', 'cost_price']);
        $frames = $optical->where('type', 'frame')->values();
        $lenses = $optical->where('type', 'lens')->values();
        foreach ($branchIds as $branchId) {
            $rows = [];
            foreach ($optical as $p) $rows[] = ['clinic_id' => $clinicId, 'branch_id' => $branchId, 'optical_product_id' => $p->id, 'quantity' => mt_rand(0, 30), 'reorder_level' => 2] + $ts;
            DB::table('optical_product_stocks')->insert($rows);
            $rows = [];
            foreach (['1.56', '1.61'] as $index) foreach (['HMC', 'Blue cut'] as $coating) for ($s = -24; $s <= 16; $s++) for ($c = 0; $c >= -8; $c--) {
                $rows[] = ['clinic_id' => $clinicId, 'branch_id' => $branchId, 'lens_index' => $index, 'coating' => $coating, 'sphere' => $s / 4, 'cylinder' => $c / 4,
                    'quantity' => mt_rand(0, 12), 'reorder_level' => 2, 'design' => 'single_vision', 'selling_price' => 120] + $ts;
            }
            foreach (array_chunk($rows, 1000) as $chunk) DB::table('optical_lens_blanks')->insert($chunk);
        }

        $expenseCats = [];
        foreach (['Rent', 'Utilities', 'Salaries', 'Lab bills', 'Supplies', 'Transport', 'Repairs', 'Other'] as $name) {
            $expenseCats[] = DB::table('expense_categories')->insertGetId(['clinic_id' => $clinicId, 'name' => $name, 'section' => 'operating', 'is_active' => true] + $ts);
        }

        // Patients.
        $patients = [];
        foreach ($branchIds as $branchId) {
            $rows = [];
            for ($i = 1; $i <= 2000 * (count($branchIds) === 1 ? 2 : 1); $i++) {
                $created = now()->subDays(mt_rand(0, $this->days + 600))->toDateTimeString();
                $rows[] = ['clinic_id' => $clinicId, 'home_branch_id' => $branchId, 'uuid' => (string) Str::uuid(), 'user_id' => $staff[$branchId]['Secretary'],
                    'pxnumber' => sprintf('LT-%d-%d-%05d', $n, $branchId, $i), 'name' => $this->name(), 'dob' => now()->subDays(mt_rand(3000, 30000))->toDateString(),
                    'gender' => mt_rand(0, 1) ? 'Male' : 'Female', 'contact' => '0'.mt_rand(200000000, 599999999), 'created_at' => $created, 'updated_at' => $created];
            }
            foreach (array_chunk($rows, 1000) as $chunk) DB::table('patients')->insert($chunk);
            $patients[$branchId] = DB::table('patients')->where('clinic_id', $clinicId)->where('home_branch_id', $branchId)->pluck('id')->all();
        }

        foreach ($branchIds as $branchId) {
            $this->days($clinicId, $branchId, $staff[$branchId], $patients[$branchId], $products, $frames, $lenses, $expenseCats);
        }
    }

    /** A year of daily work at one branch. */
    private function days(int $clinicId, int $branchId, array $staff, array $patients, array $products, $frames, $lenses, array $expenseCats): void
    {
        $base = ['clinic_id' => $clinicId, 'branch_id' => $branchId];
        $productIds = array_keys($products);
        for ($d = $this->days; $d >= 0; $d--) {
            $day = now()->subDays($d);
            if ($day->isSunday()) continue;
            $at = fn () => $day->copy()->setTime(mt_rand(8, 17), mt_rand(0, 59))->toDateTimeString();

            // Visits: clearance, visit, consultation, refraction, a paid consultation sale.
            $visits = mt_rand(10, 22);
            $todays = (array) array_rand($patients, $visits); // one visit per patient per day
            for ($v = 0; $v < $visits; $v++) {
                $time = $at();
                $ts = ['created_at' => $time, 'updated_at' => $time];
                $patient = $patients[$todays[$v]];
                $saleId = DB::table('sales')->insertGetId($base + ['user_id' => $staff['Cashier'], 'patient_id' => $patient, 'transaction_id' => 'TX-'.Str::upper(Str::random(14)),
                    'total_amount' => 0, 'amount_paid' => 0, 'payment_status' => 'paid', 'business_line' => 'clinic', 'bill_status' => 'final', 'finalized_at' => $time, 'finalized_by' => $staff['Cashier']] + $ts);
                $clearance = DB::table('cashier_patient_clearances')->insertGetId($base + ['uuid' => (string) Str::uuid(), 'user_id' => $staff['Cashier'], 'patient_id' => $patient,
                    'payment_status' => 'Paid', 'doctor_status' => 1, 'clearance_date' => $day->toDateString(), 'sale_id' => $saleId] + $ts);
                DB::table('patient_visits')->insert($base + ['patient_id' => $patient, 'clearance_id' => $clearance, 'visit_number' => 'V'.$clearance, 'opened_on' => $day->toDateString()] + $ts);
                $consultation = DB::table('consultations')->insertGetId($base + ['user_id' => $staff['Doctor'], 'patient_id' => $patient, 'clearance_id' => $clearance,
                    'chiefComplaint' => 'Blurred vision', 'vaOD6m' => '6/9', 'vaOS6m' => '6/12', 'IOPOD' => mt_rand(10, 21), 'IOPOS' => mt_rand(10, 21), 'notes' => 'Routine review'] + $ts);
                $od = sprintf('%+.2f', mt_rand(-16, 12) / 4);
                $os = sprintf('%+.2f', mt_rand(-16, 12) / 4);
                DB::table('refractions')->insert($base + ['user_id' => $staff['Doctor'], 'consultation_id' => $consultation, 'refractionOD' => "S: $od C: -0.50 A: 90",
                    'refractionOS' => "S: $os C: -0.25 A: 180", 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6', 'lensType' => 'Single Vision', 'pd' => mt_rand(58, 70)] + $ts);
                $items = [['product_id' => null, 'price' => 100.0]];
                if (mt_rand(1, 3) === 1) $items[] = ['product_id' => $pid = $productIds[array_rand($productIds)], 'price' => (float) $products[$pid]];
                $total = 0;
                foreach ($items as $item) {
                    DB::table('sale_items')->insert($base + ['sale_id' => $saleId, 'product_id' => $item['product_id'], 'prescribed_quantity' => 1, 'dispensed_quantity' => 1,
                        'selling_price' => $item['price'], 'subtotal' => $item['price']] + $ts);
                    $total += $item['price'];
                }
                DB::table('sales')->where('id', $saleId)->update(['total_amount' => $total, 'amount_paid' => $total]);
                DB::table('payment_transactions')->insert($base + ['sale_id' => $saleId, 'amount' => $total, 'payment_method' => mt_rand(0, 2) ? 'cash' : 'momo', 'collected_by' => $staff['Cashier']] + $ts);
            }

            // Optical orders: deposit on the day, balance at collection.
            $orders = mt_rand(4, 12);
            for ($o = 0; $o < $orders; $o++) {
                $time = $at();
                $ts = ['created_at' => $time, 'updated_at' => $time];
                $frame = $frames[mt_rand(0, $frames->count() - 1)];
                $lens = $lenses[mt_rand(0, $lenses->count() - 1)];
                $total = (float) $frame->selling_price + (float) $lens->selling_price;
                $age = $d;
                $status = match (true) {
                    $age > 21 => mt_rand(1, 30) === 1 ? 'Cancelled' : 'Collected',
                    $age > 10 => ['Collected', 'Collected', 'Ready for Collection'][mt_rand(0, 2)],
                    $age > 3 => ['Sent to Lab', 'In Production', 'Ready for Collection'][mt_rand(0, 2)],
                    default => ['Pending', 'Sent to Lab'][mt_rand(0, 1)],
                };
                $paid = $status === 'Collected' ? $total : round($total * 0.5, 2);
                $saleId = DB::table('sales')->insertGetId($base + ['user_id' => $staff['Secretary'], 'patient_id' => $patient = $patients[array_rand($patients)], 'transaction_id' => 'OPT-'.Str::upper(Str::random(14)),
                    'total_amount' => $total, 'amount_paid' => $paid, 'payment_status' => $paid >= $total ? 'paid' : 'partial', 'business_line' => 'optical'] + $ts);
                foreach ([$frame, $lens] as $p) DB::table('sale_items')->insert($base + ['sale_id' => $saleId, 'optical_product_id' => $p->id, 'prescribed_quantity' => 0, 'dispensed_quantity' => 1,
                    'selling_price' => $p->selling_price, 'subtotal' => $p->selling_price] + $ts);
                DB::table('payment_transactions')->insert($base + ['sale_id' => $saleId, 'amount' => round($total * 0.5, 2), 'payment_method' => 'cash', 'collected_by' => $staff['Secretary'], 'notes' => 'Optical order deposit'] + $ts);
                $collected = null;
                if ($status === 'Collected') {
                    $collected = $day->copy()->addDays(mt_rand(5, 14))->setTime(14, 0)->toDateTimeString();
                    DB::table('payment_transactions')->insert($base + ['sale_id' => $saleId, 'amount' => $total - round($total * 0.5, 2), 'payment_method' => 'momo', 'collected_by' => $staff['Secretary'],
                        'created_at' => $collected, 'updated_at' => $collected]);
                }
                $orderId = DB::table('lens_orders')->insertGetId($base + ['user_id' => $staff['Secretary'], 'patient_id' => $patient, 'sale_id' => $saleId,
                    'order_id' => 'OPT-'.Str::upper(Str::random(12)), 'frame_optical_product_id' => $frame->id, 'lens_optical_product_id' => $lens->id,
                    'frame_price' => $frame->selling_price, 'lens_price' => $lens->selling_price, 'frame_unit_cost' => $frame->cost_price, 'lens_unit_cost' => $lens->cost_price,
                    'lens_supply_source' => 'catalogue', 'order_source' => 'in_clinic', 'work_type' => 'prescription', 'bill_to' => 'customer',
                    'prescription_snapshot' => json_encode(['od' => ['sphere' => '-1.25', 'cylinder' => '-0.50', 'axis' => 90], 'os' => ['sphere' => '-1.00', 'cylinder' => '-0.25', 'axis' => 180], 'pd' => 64]),
                    'status' => $status, 'paid_amount' => $paid, 'pickUpDate' => $day->copy()->addDays(7)->toDateString(), 'collected_at' => $collected,
                    'cancelled_at' => $status === 'Cancelled' ? $time : null, 'status_changed_at' => $collected ?? $time,
                    'ready_at' => in_array($status, ['Collected', 'Ready for Collection'], true) ? $day->copy()->addDays(4)->toDateTimeString() : null,
                    'notes' => '{}'] + $ts);
                $events = [];
                $prev = null;
                foreach (['Pending', 'Sent to Lab', 'In Production', 'Ready for Collection', 'Collected'] as $step) {
                    $events[] = $base + ['lens_order_id' => $orderId, 'user_id' => $staff['Optician'], 'from_status' => $prev, 'to_status' => $step, 'created_at' => $time];
                    $prev = $step;
                    if ($step === $status) break;
                }
                DB::table('lens_order_events')->insert($events);
            }

            // Expenses.
            for ($e = mt_rand(1, 4); $e > 0; $e--) {
                $time = $at();
                DB::table('expenses')->insert($base + ['expense_category_id' => $expenseCats[array_rand($expenseCats)], 'expense_date' => $day->toDateString(), 'description' => 'Daily expense',
                    'amount' => mt_rand(2000, 150000) / 100, 'payment_method' => 'cash', 'recorded_by' => $staff['Manager'], 'business_line' => mt_rand(0, 1) ? 'clinic' : 'optical',
                    'created_at' => $time, 'updated_at' => $time]);
            }
        }
    }

    private function name(): string
    {
        static $first = ['Kwame', 'Ama', 'Kofi', 'Akosua', 'Yaw', 'Abena', 'Kojo', 'Efua', 'Kwesi', 'Adwoa', 'Esi', 'Nana', 'Mensah', 'Afia', 'Yaa'];
        static $last = ['Mensah', 'Owusu', 'Boateng', 'Asante', 'Osei', 'Appiah', 'Addo', 'Ofori', 'Agyeman', 'Darko', 'Quaye', 'Tetteh', 'Annan', 'Badu'];

        return $first[array_rand($first)].' '.$last[array_rand($last)];
    }
}
