<?php

namespace App\Console\Commands;

use App\Enums\DosageForm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off fill of the drug form for drugs added before forms existed (24 Nov 2026): a guess from each name
 * ("eye drops", "tab", "caps", "oint", "250mg"). Names that do not say stay unset, and the clinic finishes them
 * from the "Drugs with no form" tab on the Products page. A form already set is never changed.
 */
class GuessDosageFormsCommand extends Command
{
    protected $signature = 'products:guess-dosage-forms {clinic? : Only this clinic id (default: every clinic)}
        {--commit : Save the guesses (otherwise a dry run)}';

    protected $description = 'Guess the form (drops, tablet...) of drugs that have none, from their names (dry run unless --commit)';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $rows = DB::table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('products.deleted_at')->whereNull('products.dosage_form')
            ->where(fn ($q) => $q->where('categories.type', 'drug')
                ->orWhereIn(DB::raw('LOWER(categories.name)'), ['drug', 'drugs', 'medication', 'medications']))
            ->when($this->argument('clinic'), fn ($q, $clinic) => $q->where('products.clinic_id', $clinic))
            ->orderBy('products.clinic_id')->orderBy('products.name')
            ->get(['products.id', 'products.clinic_id', 'products.name']);

        $report = [];
        $counts = [];
        foreach ($rows as $row) {
            $form = DosageForm::guess($row->name);
            $counts[$form?->label() ?? 'Not set (name does not say)'] = ($counts[$form?->label() ?? 'Not set (name does not say)'] ?? 0) + 1;
            if ($form && $commit) DB::table('products')->where('id', $row->id)->whereNull('dosage_form')->update(['dosage_form' => $form->value]);
            if ($this->output->isVerbose()) $report[] = [$row->clinic_id, $row->name, $form?->label() ?? '—'];
        }

        $this->info(($commit ? 'SAVED' : 'DRY RUN') . ': ' . $rows->count() . ' drugs with no form');
        if ($report) $this->table(['Clinic', 'Drug', 'Guess'], $report);
        arsort($counts);
        $this->table(['Form', 'Drugs'], collect($counts)->map(fn ($n, $label) => [$label, $n])->values()->all());
        if (!$commit) $this->comment('Nothing was changed. Add -v to list every drug, and --commit to save the guesses.');

        return self::SUCCESS;
    }
}
