<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseDemoSeeder extends Seeder
{
    // Monthly expense templates: [category_name, description, min_amount, max_amount, entries_per_month]
    private const TEMPLATES = [
        ['Staff Salaries',   'Staff payroll',                    18000, 30000, 1],
        ['Rent / Utilities', 'Monthly office rent',               2500,  4000, 1],
        ['Rent / Utilities', 'Electricity bill',                   800,  1500, 1],
        ['Rent / Utilities', 'Water & sanitation bill',            150,   300, 1],
        ['Rent / Utilities', 'Internet & telephone',               400,   700, 1],
        ['Supplies',         'Ophthalmic consumables',             500,  2000, 1],
        ['Supplies',         'Office stationery & printing',       200,   600, 1],
        ['Supplies',         'Cleaning supplies',                  100,   300, 1],
        ['Equipment',        'Equipment servicing & calibration',  300,  5000, 0], // occasional
        ['Maintenance',      'Building maintenance',               200,  2000, 0], // occasional
        ['Marketing',        'Social media & online advertising',  500,  2000, 1],
        ['Bank Charges',     'Bank service charges',               100,   500, 1],
        ['Miscellaneous',    'Petty cash & sundries',              100,   500, 1],
        ['Miscellaneous',    'Transport & logistics',              200,   800, 1],
    ];

    public function run(): void
    {
        $existing = DB::table('expenses')->count();

        // Estimate target: 74 months × ~12 entries = ~888 rows
        $target = 900;

        if ($existing >= $target) {
            $this->command->info('ExpenseDemoSeeder: already seeded (' . number_format($existing) . ' expenses).');
            return;
        }

        // Load category IDs (seeded by the migration itself)
        $catMap = DB::table('expense_categories')
            ->pluck('id', 'name')
            ->toArray();

        if (empty($catMap)) {
            $this->command->error('No expense categories found. Run migrations first.');
            return;
        }

        $userIds  = DB::table('users')->pluck('id')->toArray();
        $start    = Carbon::create(2020, 1, 1);
        $end      = Carbon::now()->startOfMonth();
        $rows     = [];

        $current = $start->copy();
        while ($current->lte($end)) {
            $monthLabel = $current->format('M Y');

            foreach (self::TEMPLATES as [$catName, $desc, $min, $max, $perMonth]) {
                $catId = $catMap[$catName] ?? null;
                if (!$catId) continue;

                // Occasional entries: 40% chance each month
                $occurrences = $perMonth > 0 ? $perMonth : (rand(1, 10) <= 4 ? 1 : 0);

                for ($j = 0; $j < $occurrences; $j++) {
                    $day  = rand(1, $current->daysInMonth);
                    $date = $current->copy()->setDay($day)->format('Y-m-d');

                    // Apply ~15% annual growth factor on top of base amount
                    $yearsElapsed = $current->year - 2020;
                    $factor       = 1 + ($yearsElapsed * 0.08); // 8% per year
                    $amount       = round(rand($min, $max) * $factor, 2);

                    $rows[] = [
                        'expense_category_id' => $catId,
                        'expense_date'        => $date,
                        'description'         => $perMonth > 0 ? $desc . ' — ' . $monthLabel : $desc,
                        'amount'              => $amount,
                        'reference'           => rand(1, 10) <= 3 ? 'REF-' . strtoupper(substr(md5(rand()), 0, 8)) : null,
                        'notes'               => null,
                        'recorded_by'         => $userIds[array_rand($userIds)],
                        'created_at'          => $date . ' 10:00:00',
                        'updated_at'          => $date . ' 10:00:00',
                    ];
                }
            }

            $current->addMonth();
        }

        if (empty($rows)) {
            $this->command->warn('No expense rows generated.');
            return;
        }

        $this->command->info("Seeding " . count($rows) . " expense records...");
        $bar = $this->command->getOutput()->createProgressBar(count($rows));
        $bar->start();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('expenses')->insert($chunk);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('ExpenseDemoSeeder done — ' . number_format(DB::table('expenses')->count()) . ' total expenses.');
    }
}
