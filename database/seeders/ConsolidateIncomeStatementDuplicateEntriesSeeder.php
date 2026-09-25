<?php

namespace Database\Seeders;

use App\Models\IncomeStatementEntry;
use Illuminate\Database\Seeder;

class ConsolidateIncomeStatementDuplicateEntriesSeeder extends Seeder
{
    public function run()
    {
        IncomeStatementEntry::where('is_active', true)
            ->get()
            ->groupBy(function ($entry) {
                return $entry->section . '|' . $entry->name . '|' . $entry->entry_date->format('Y-m');
            })
            ->each(function ($entries) {
                if ($entries->count() <= 1) {
                    return;
                }

                $keeper = $entries->sortByDesc('id')->first();

                $entries->where('id', '!=', $keeper->id)->each(function ($entry) {
                    $entry->delete();
                });
            });
    }
}
