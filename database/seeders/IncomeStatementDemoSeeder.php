<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IncomeStatementDemoSeeder extends Seeder
{
    // [section, name, base_amount] — amounts grow ~15% per year from 2020 baseline
    private const LINE_ITEMS = [
        ['Revenue',              'Consultation Fees',    15000],
        ['Revenue',              'Optical Sales',        28000],
        ['Revenue',              'Contact Lens Sales',    8000],
        ['Revenue',              'Other Services',        5000],
        ['Cost of Goods Sold',   'Cost of Frames',        8500],
        ['Cost of Goods Sold',   'Cost of Lenses',        6000],
        ['Cost of Goods Sold',   'Cost of Eye Drops',     2000],
        ['Operating Expenses',   'Staff Salaries',       18000],
        ['Operating Expenses',   'Rent',                  2800],
        ['Operating Expenses',   'Utilities',             1500],
        ['Operating Expenses',   'Marketing',              600],
        ['Operating Expenses',   'Miscellaneous',          400],
    ];

    public function run(): void
    {
        $existing = DB::table('income_statement_entries')->count();
        // 74 months × 12 line items = ~888 rows
        $target = 900;

        if ($existing >= $target) {
            $this->command->info('IncomeStatementDemoSeeder: already seeded (' . number_format($existing) . ' entries).');
            return;
        }

        $start = Carbon::create(2020, 1, 1);
        $end   = Carbon::now()->startOfMonth()->subMonth(); // up to last completed month
        $rows  = [];

        $current = $start->copy();
        while ($current->lte($end)) {
            $yearsElapsed = $current->diffInYears($start);
            // Compound growth: 15% per year
            $factor = pow(1.15, $yearsElapsed);
            // Add monthly seasonality — eye clinics are busier in Jan, Apr, Aug, Dec
            $busyMonths = [1, 4, 8, 12];
            $seasonal   = in_array($current->month, $busyMonths) ? 1.12 : 1.0;
            $entryDate  = $current->copy()->endOfMonth()->format('Y-m-d');

            foreach (self::LINE_ITEMS as [$section, $name, $base]) {
                // Add ±15% randomness to make charts look natural
                $variance = (rand(85, 115) / 100);
                $amount   = round($base * $factor * $seasonal * $variance, 2);

                $rows[] = [
                    'section'    => $section,
                    'name'       => $name,
                    'amount'     => $amount,
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

        if (empty($rows)) {
            $this->command->warn('No income statement rows generated.');
            return;
        }

        $this->command->info("Seeding " . count($rows) . " income statement entries...");
        $bar = $this->command->getOutput()->createProgressBar(count($rows));
        $bar->start();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('income_statement_entries')->insert($chunk);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('IncomeStatementDemoSeeder done — ' . number_format(DB::table('income_statement_entries')->count()) . ' entries.');
    }
}
