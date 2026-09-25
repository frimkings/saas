<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosSalesSeeder extends Seeder
{
    private const TOTAL           = 250_000;
    private const CHUNK           = 500;
    private const PAYMENT_METHODS = ['cash', 'momo', 'card', 'bank_transfer', 'insurance'];
    private const FREQUENCIES     = ['OD', 'OS', 'OU', 'BD', 'TDS', null];
    private const EYES            = ['OD', 'OS', 'OU', null];

    // [name, category_name, cost_price, selling_price]
    private const PRODUCTS = [
        ['Ray-Ban RB2140',           'Frames',         150, 280],
        ['Oakley Holbrook',           'Frames',         180, 350],
        ['Silhouette Titan',          'Frames',         200, 420],
        ['Gucci GG0010O',             'Frames',         250, 520],
        ['Generic Frame A',           'Frames',          40,  90],
        ['Generic Frame B',           'Frames',          35,  80],
        ['Titanium Frame',            'Frames',         120, 240],
        ['Acetate Frame Classic',     'Frames',          60, 130],
        ['Single Vision CR-39',       'Lenses',          50, 110],
        ['Progressive CR-39',         'Lenses',         120, 260],
        ['Anti-Reflective Lens',      'Lenses',          80, 175],
        ['Photochromic Lens',         'Lenses',         130, 290],
        ['Blue-Light Blocking Lens',  'Lenses',          90, 200],
        ['Bifocal Lens',              'Lenses',          70, 155],
        ['High-Index 1.67',           'Lenses',         160, 340],
        ['Visine Original 15ml',      'Eye Drops',        8,  18],
        ['Tears Naturale 15ml',       'Eye Drops',       12,  25],
        ['Tobradex Eye Drop 5ml',     'Eye Drops',       25,  52],
        ['Maxitrol Eye Drop 5ml',     'Eye Drops',       20,  42],
        ['Voltaren Ophtha 5ml',       'Eye Drops',       18,  38],
        ['Betoptic 5ml',              'Eye Drops',       22,  46],
        ['Timolol 0.5% 5ml',          'Eye Drops',       15,  32],
        ['Acuvue Oasys (6pk)',        'Contact Lenses',  45,  95],
        ['Dailies Total1 (30pk)',     'Contact Lenses',  60, 128],
        ['Biofinity Monthly (6pk)',   'Contact Lenses',  50, 108],
        ['Air Optix Plus (6pk)',      'Contact Lenses',  48, 102],
        ['Polarised Sunglasses',      'Sunglasses',      60, 130],
        ['Sport Wrap Sunglasses',     'Sunglasses',      70, 150],
        ['Driving Sunglasses',        'Sunglasses',      55, 118],
        ['Microfibre Cloth',          'Accessories',      2,   6],
        ['Hard Eyeglass Case',        'Accessories',      5,  12],
        ['Anti-Fog Spray 30ml',       'Accessories',      8,  18],
        ['Neck Cord / Lanyard',       'Accessories',      3,   8],
        ['Repair Kit',                'Accessories',      4,  10],
        ['Chloramphenicol Eye Oint',  'Drugs',            5,  12],
        ['Gentamicin Eye Drop 5ml',   'Drugs',           10,  22],
        ['Dexamethasone Eye Drop',    'Drugs',           14,  30],
        ['Fusidic Acid Eye Gel',      'Drugs',           16,  34],
        ['Eye Examination',           'Services',         0,  80],
        ['Contact Lens Fitting',      'Services',         0,  60],
        ['Frame Adjustment',          'Services',         0,  20],
        ['Lens Replacement',          'Services',         0,  45],
    ];

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

    public function run(): void
    {
        $adminId = DB::table('users')->value('id');

        // ── 1. Seed categories ──────────────────────────────────────
        $categoryIds = [];
        foreach (self::CATEGORIES as $name => $type) {
            $row = DB::table('categories')->where('name', $name)->first();
            if ($row) {
                $categoryIds[$name] = $row->id;
            } else {
                $categoryIds[$name] = DB::table('categories')->insertGetId([
                    'user_id'    => $adminId,
                    'name'       => $name,
                    'type'       => $type,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ── 2. Seed products ────────────────────────────────────────
        $products = [];
        foreach (self::PRODUCTS as [$name, $catName, $cost, $sell]) {
            $row = DB::table('products')->where('name', $name)->first();
            $id  = $row
                ? $row->id
                : DB::table('products')->insertGetId([
                    'user_id'       => $adminId,
                    'name'          => $name,
                    'category_id'   => $categoryIds[$catName],
                    'quantity'      => rand(50, 500),
                    'cost_price'    => $cost,
                    'selling_price' => $sell,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            $products[] = ['id' => $id, 'cost' => $cost, 'sell' => $sell];
        }
        $productCount = count($products);

        // ── 3. Load consultations ───────────────────────────────────
        $consultations = DB::table('consultations')
            ->select('id', 'patient_id', 'user_id')
            ->get()
            ->toArray();

        if (empty($consultations)) {
            $this->command->error('No consultations found. Run ConsultationSeeder first.');
            return;
        }

        $userIds      = DB::table('users')->pluck('id')->toArray();
        $totalConst   = count($consultations);
        $start        = Carbon::create(2022, 1, 1)->timestamp;
        $end          = Carbon::now()->timestamp;
        // Unique TXN counter — offset past any existing IDs
        $txnBase      = DB::table('sales')->count();

        $this->command->info("Seeding " . number_format(self::TOTAL) . " POS sales...");
        $bar = $this->command->getOutput()->createProgressBar(self::TOTAL);
        $bar->start();

        $seeded = 0;

        while ($seeded < self::TOTAL) {
            $count       = min(self::CHUNK, self::TOTAL - $seeded);
            $salesInsert = [];
            $meta        = [];

            for ($i = 0; $i < $count; $i++) {
                $global       = $txnBase + $seeded + $i + 1;
                $txnId        = 'TXN' . str_pad($global, 9, '0', STR_PAD_LEFT);
                $consultation = $consultations[($seeded + $i) % $totalConst];
                $date         = Carbon::createFromTimestamp(rand($start, $end))->format('Y-m-d H:i:s');

                $numItems  = rand(1, min(4, $productCount));
                $itemKeys  = (array) array_rand($products, $numItems);

                $total  = 0;
                $profit = 0;
                $lineItems = [];
                foreach ($itemKeys as $key) {
                    $qty     = rand(1, 3);
                    $price   = $products[$key]['sell'];
                    $total  += $price * $qty;
                    $profit += ($price - $products[$key]['cost']) * $qty;
                    $lineItems[] = ['key' => $key, 'qty' => $qty];
                }

                $status     = $this->paymentStatus();
                $amountPaid = match ($status) {
                    'paid'    => $total,
                    'partial' => round($total * (rand(30, 80) / 100), 2),
                    default   => 0.00,
                };

                $salesInsert[] = [
                    'user_id'         => $consultation->user_id,
                    'patient_id'      => $consultation->patient_id,
                    'consultation_id' => $consultation->id,
                    'transaction_id'  => $txnId,
                    'total_amount'    => $total,
                    'amount_paid'     => $amountPaid,
                    'payment_status'  => $status,
                    'profit'          => $profit,
                    'discount_amount' => 0,
                    'is_refunded'     => false,
                    'created_at'      => $date,
                    'updated_at'      => $date,
                ];

                $meta[$txnId] = ['items' => $lineItems, 'date' => $date, 'amount_paid' => $amountPaid];
            }

            // Wrap all three inserts in one transaction — retries up to 3× on deadlock
            DB::transaction(function () use ($salesInsert, $meta, $products, $userIds) {
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
                        $p = $products[$li['key']];
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
                            'collected_by'   => $userIds[array_rand($userIds)],
                            'created_at'     => $m['date'],
                            'updated_at'     => $m['date'],
                        ];
                    }
                }

                if ($itemRows)    DB::table('sale_items')->insert($itemRows);
                if ($paymentRows) DB::table('payment_transactions')->insert($paymentRows);
            }, 3); // retry up to 3 times on deadlock

            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('Done — ' . number_format(self::TOTAL) . ' sales seeded.');
        $this->command->info('Total sales:        ' . number_format(DB::table('sales')->count()));
        $this->command->info('Total sale_items:   ' . number_format(DB::table('sale_items')->count()));
        $this->command->info('Payment records:    ' . number_format(DB::table('payment_transactions')->count()));
    }

    private function paymentStatus(): string
    {
        $r = rand(1, 100);
        if ($r <= 75) return 'paid';
        if ($r <= 90) return 'partial';
        return 'unpaid';
    }
}
