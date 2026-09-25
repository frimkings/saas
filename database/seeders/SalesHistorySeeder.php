<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesHistorySeeder extends Seeder
{
    private const CHUNK  = 200;
    private const TARGET = 100_000;

    // Date window: 2023-01-01 → 2026-05-20
    private const START_TS = 1672531200; // 2023-01-01 00:00:00 UTC
    private const END_TS   = 1747785599; // 2026-05-20 23:59:59 UTC

    public function run(): void
    {
        DB::disableQueryLog();
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // ── Pre-fetch lookup tables ──────────────────────────────────────
        $userIds    = DB::table('users')->pluck('id')->toArray();
        $patientIds = DB::table('patients')->pluck('id')->toArray();

        if (empty($userIds)) {
            $this->command->error('No users found. Run php artisan db:seed first.');
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            return;
        }

        if (empty($patientIds)) {
            $this->command->warn('No patients found — creating 200 seed patients...');
            $this->seedPatients($userIds);
            $patientIds = DB::table('patients')->pluck('id')->toArray();
        }

        // ── Ensure products exist ────────────────────────────────────────
        $products = DB::table('products')->whereNull('deleted_at')->get(['id', 'cost_price', 'selling_price'])->toArray();

        if (count($products) < 5) {
            $this->command->warn('No products found — creating clinic product catalogue...');
            $this->seedProducts($userIds);
            $products = DB::table('products')->whereNull('deleted_at')->get(['id', 'cost_price', 'selling_price'])->toArray();
        }

        $productsArr  = array_map(fn ($p) => [
            'id'   => $p->id,
            'cost' => (float) $p->cost_price,
            'sell' => (float) $p->selling_price,
        ], $products);
        $productCount = count($productsArr);

        // ── Main seeding loop ────────────────────────────────────────────
        $seeded = 0;
        $this->command->info('Seeding ' . number_format(self::TARGET) . ' sales (2023 – 2026)...');

        while ($seeded < self::TARGET) {
            $batch = min(self::CHUNK, self::TARGET - $seeded);

            $salesRows   = [];
            $itemsGroups = [];

            for ($i = 0; $i < $batch; $i++) {
                $createdAt = $this->randomDate();
                $status    = $this->randomStatus();
                $userId    = $userIds[array_rand($userIds)];
                $patientId = $patientIds[array_rand($patientIds)];

                // 1–4 distinct products per sale
                $numItems   = rand(1, min(4, $productCount));
                $pickedIdxs = (array) array_rand($productsArr, $numItems);

                $total  = 0.0;
                $profit = 0.0;
                $items  = [];

                foreach ($pickedIdxs as $idx) {
                    $p   = $productsArr[$idx];
                    $qty = rand(1, 5);
                    $sub = round($p['sell'] * $qty, 2);
                    $total  += $sub;
                    $profit += round(($p['sell'] - $p['cost']) * $qty, 2);
                    $items[] = [
                        'product_id'          => $p['id'],
                        'prescribed_quantity' => $qty,
                        'dispensed_quantity'  => $qty,
                        'selling_price'       => $p['sell'],
                        'subtotal'            => $sub,
                        'created_at'          => $createdAt,
                        'updated_at'          => $createdAt,
                    ];
                }

                $total      = round($total, 2);
                $amountPaid = match ($status) {
                    'paid'    => $total,
                    'partial' => round($total * (rand(30, 90) / 100), 2),
                    default   => 0.00,
                };

                $isRefunded = false;
                $refundedAt = null;
                if ($status === 'paid' && rand(1, 100) <= 3) {
                    $isRefunded = true;
                    $refundTs   = strtotime($createdAt) + rand(3_600, 604_800);
                    $refundedAt = date('Y-m-d H:i:s', min($refundTs, self::END_TS));
                }

                $salesRows[]   = [
                    'user_id'        => $userId,
                    'patient_id'     => $patientId,
                    'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(5))),
                    'total_amount'   => $total,
                    'amount_paid'    => $amountPaid,
                    'payment_status' => $status,
                    'profit'         => $profit,
                    'is_refunded'    => $isRefunded,
                    'refunded_at'    => $refundedAt,
                    'created_at'     => $createdAt,
                    'updated_at'     => $createdAt,
                ];
                $itemsGroups[] = $items;
            }

            // Insert sales — MySQL returns the first auto-increment ID for a multi-row INSERT
            DB::table('sales')->insert($salesRows);
            $firstId = (int) DB::getPdo()->lastInsertId();

            // Attach sale IDs to their items and bulk-insert
            $allItems = [];
            foreach ($itemsGroups as $offset => $items) {
                $saleId = $firstId + $offset;
                foreach ($items as $item) {
                    $item['sale_id'] = $saleId;
                    $allItems[]      = $item;
                }
            }
            DB::table('sale_items')->insert($allItems);

            $seeded += $batch;
            $this->command->getOutput()->write("\r  $seeded / " . self::TARGET);
        }

        $this->command->line('');
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $itemCount = DB::table('sale_items')->count();
        $this->command->info(sprintf(
            'Done. %s sales + %s sale items inserted.',
            number_format($seeded),
            number_format($itemCount)
        ));
    }

    private function randomDate(): string
    {
        return date('Y-m-d H:i:s', rand(self::START_TS, self::END_TS));
    }

    private function randomStatus(): string
    {
        $r = rand(1, 100);
        if ($r <= 85) return 'paid';
        if ($r <= 95) return 'partial';
        return 'unpaid';
    }

    private function seedPatients(array $userIds): void
    {
        $faker  = \Faker\Factory::create();
        $userId = $userIds[array_rand($userIds)];
        $rows   = [];

        for ($i = 0; $i < 200; $i++) {
            $gender = $faker->randomElement(['Male', 'Female']);
            $rows[] = [
                'user_id'    => $userId,
                'name'       => $faker->name($gender),
                'pxnumber'   => 'PX-' . strtoupper(bin2hex(random_bytes(4))),
                'contact'    => $faker->phoneNumber(),
                'gender'     => $gender,
                'dob'        => $faker->dateTimeBetween('-85 years', '-18 years')->format('Y-m-d'),
                'address'    => $faker->address(),
                'occupation' => $faker->jobTitle(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('patients')->insert($rows);
        $this->command->info('Created 200 seed patients.');
    }

    private function seedProducts(array $userIds): void
    {
        $userId = $userIds[array_rand($userIds)];

        $catId = DB::table('categories')->value('id')
            ?? DB::table('categories')->insertGetId([
                'user_id'    => $userId,
                'name'       => 'General',
                'type'       => 'other',
                'is_active'  => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $catalogue = [
            ['Timolol 0.5% Eye Drops',      12.00,  28.00],
            ['Latanoprost 0.005% Drops',    18.00,  42.00],
            ['Lubricant Eye Drops 10ml',     5.50,  15.00],
            ['Acetazolamide 250mg (pack)',    8.00,  20.00],
            ['Prednisolone Eye Drops',       14.00,  32.00],
            ['Ciprofloxacin Eye Drops',      10.00,  24.00],
            ['Eye Patch (sterile)',           2.50,   7.50],
            ['Single Vision CR-39 Lenses',  40.00,  95.00],
            ['Single Vision HiIndex 1.67',  70.00, 170.00],
            ['Bifocal Lenses (D28)',         65.00, 155.00],
            ['Progressive Lenses (Std)',    110.00, 280.00],
            ['Acetate Frame – Standard',     30.00,  80.00],
            ['Metal Frame – Classic',        25.00,  70.00],
            ['Titanium Frame – Slim',        80.00, 200.00],
            ['Anti-Reflective Coating',      15.00,  45.00],
            ['UV400 Tint Treatment',         12.00,  35.00],
            ['Photochromic Upgrade',         45.00, 120.00],
            ['Hard Coat Upgrade',             8.00,  22.00],
            ['Contact Lens (monthly, pair)', 22.00,  55.00],
            ['Lens Cleaning Kit',             4.00,  12.00],
        ];

        foreach ($catalogue as [$name, $cost, $sell]) {
            DB::table('products')->insertOrIgnore([
                'user_id'          => $userId,
                'category_id'      => $catId,
                'name'             => $name,
                'batch_number'     => 'SEED' . strtoupper(bin2hex(random_bytes(3))),
                'quantity'         => rand(100, 1000),
                'cost_price'       => $cost,
                'selling_price'    => $sell,
                'manufacture_date' => '2022-01-01',
                'expiry_date'      => '2028-12-31',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        $this->command->info('Created ' . count($catalogue) . ' seed products.');
    }
}
