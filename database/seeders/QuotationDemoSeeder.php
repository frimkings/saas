<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuotationDemoSeeder extends Seeder
{
    private const TARGET = 3_000;
    private const CHUNK  = 200;

    private const STATUSES = [
        'accepted','accepted','accepted','accepted', // 35%
        'sent','sent','sent',                        // 25%+
        'expired','expired',                         // ~18%
        'draft','draft',                             // ~17%
        'cancelled',                                 // ~5%
    ];

    public function run(): void
    {
        $existing = DB::table('quotations')->count();
        $toSeed   = self::TARGET - $existing;

        if ($toSeed <= 0) {
            $this->command->info('QuotationDemoSeeder: already at target (' . number_format($existing) . ' quotations).');
            return;
        }

        $products = DB::table('products')->select('id', 'name', 'selling_price')->get()->toArray();
        if (empty($products)) {
            $this->command->error('No products found. Run PosDemoSeeder first (it seeds products).');
            return;
        }

        $patientIds   = DB::table('patients')->select('id', 'name', 'contact')->get()->toArray();
        $userIds      = DB::table('users')->pluck('id')->toArray();
        $productCount = count($products);
        $patientCount = count($patientIds);

        $start  = Carbon::create(2020, 1, 1)->timestamp;
        $end    = Carbon::now()->timestamp;
        $maxNum = (int) DB::table('quotations')
            ->selectRaw("MAX(CAST(SUBSTRING(quotation_number, 3) AS UNSIGNED)) as m")
            ->value('m');

        $this->command->info("Seeding {$toSeed} quotations (target: " . number_format(self::TARGET) . ')...');
        $bar = $this->command->getOutput()->createProgressBar($toSeed);
        $bar->start();

        $seeded = 0;
        while ($seeded < $toSeed) {
            $count      = min(self::CHUNK, $toSeed - $seeded);
            $quotRows   = [];
            $itemBatches = [];

            for ($i = 0; $i < $count; $i++) {
                $issueDate  = Carbon::createFromTimestamp(rand($start, $end));
                $validUntil = $issueDate->copy()->addDays(30);
                $status     = self::STATUSES[array_rand(self::STATUSES)];
                $noPatient  = rand(1, 5) === 1; // 20% walk-in (no patient record)

                $patientId   = null;
                $patientName = 'Walk-in Client';
                $patientPhone = '+233' . rand(200000000, 599999999);

                if (!$noPatient && $patientCount > 0) {
                    $pt          = $patientIds[rand(0, $patientCount - 1)];
                    $patientId   = $pt->id;
                    $patientName = $pt->name;
                    $patientPhone = $pt->contact ?? $patientPhone;
                }

                // Pick 2–4 items
                $numItems   = rand(2, 4);
                $itemKeys   = (array) array_rand(range(0, $productCount - 1), min($numItems, $productCount));
                $subtotal   = 0;
                $lineItems  = [];

                foreach ($itemKeys as $key) {
                    $product  = $products[$key];
                    $qty      = rand(1, 3);
                    $price    = $product->selling_price;
                    $lineSub  = $qty * $price;
                    $subtotal += $lineSub;
                    $lineItems[] = [
                        'product_id'  => $product->id,
                        'description' => $product->name,
                        'quantity'    => $qty,
                        'unit_price'  => $price,
                        'subtotal'    => $lineSub,
                    ];
                }

                $discount = rand(0, 1) ? round($subtotal * (rand(5, 15) / 100), 2) : 0;
                $total    = $subtotal - $discount;
                $qtNum    = 'QT' . str_pad($maxNum + $seeded + $i + 1, 6, '0', STR_PAD_LEFT);

                $quotRows[]   = [
                    'quotation_number' => $qtNum,
                    'patient_id'       => $patientId,
                    'patient_name'     => $patientName,
                    'patient_phone'    => $patientPhone,
                    'status'           => $status,
                    'issue_date'       => $issueDate->format('Y-m-d'),
                    'valid_until'      => $validUntil->format('Y-m-d'),
                    'notes'            => null,
                    'subtotal'         => $subtotal,
                    'discount_amount'  => $discount,
                    'total_amount'     => $total,
                    'created_by'       => $userIds[array_rand($userIds)],
                    'created_at'       => $issueDate->format('Y-m-d H:i:s'),
                    'updated_at'       => $issueDate->format('Y-m-d H:i:s'),
                ];
                $itemBatches[] = ['qt_number' => $qtNum, 'items' => $lineItems, 'date' => $issueDate->format('Y-m-d H:i:s')];
            }

            // Insert quotations then fetch back IDs
            DB::table('quotations')->insert($quotRows);
            $qtNumbers = array_column($quotRows, 'quotation_number');
            $qtMap     = DB::table('quotations')
                ->whereIn('quotation_number', $qtNumbers)
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
                    $itemRows[] = $item;
                }
            }
            if ($itemRows) {
                DB::table('quotation_items')->insert($itemRows);
            }

            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('QuotationDemoSeeder done — ' . number_format(DB::table('quotations')->count()) . ' quotations, '
            . number_format(DB::table('quotation_items')->count()) . ' items.');
    }
}
