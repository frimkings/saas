<?php

namespace Database\Seeders;

use App\Models\IncomeStatementEntry;
use App\Models\IncomeStatementTemplate;
use Illuminate\Database\Seeder;

class DefaultIncomeStatementTemplateSeeder extends Seeder
{
    public function run()
    {
        $templates = [
            [IncomeStatementEntry::OPERATING_EXPENSE, "Doctor's Salary", 5000],
            [IncomeStatementEntry::OPERATING_EXPENSE, 'Nurse Salary', 1500],
            [IncomeStatementEntry::OPERATING_EXPENSE, 'Locum', 900],
            [IncomeStatementEntry::OPERATING_EXPENSE, 'Media', 600],
            [IncomeStatementEntry::OPERATING_EXPENSE, 'Electricity', 1000],
            [IncomeStatementEntry::OPERATING_EXPENSE, 'Internet', 300],
            [IncomeStatementEntry::OPERATING_EXPENSE, 'Consumables', 150],
            [IncomeStatementEntry::OPERATING_EXPENSE, 'Rent', 3000],
            [IncomeStatementEntry::NON_OPERATING_EXPENSE, 'Loans (10,000/month)', 10000],
        ];

        foreach ($templates as [$section, $name, $amount]) {
            IncomeStatementTemplate::updateOrCreate(
                [
                    'section' => $section,
                    'name' => $name,
                ],
                [
                    'amount' => $amount,
                    'percentage' => null,
                    'notes' => null,
                    'is_active' => true,
                ]
            );
        }
    }
}
