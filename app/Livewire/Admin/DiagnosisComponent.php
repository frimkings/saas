<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Diagnosis;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Response;

class DiagnosisComponent extends Component
{
    use WithPagination, WithFileUploads;

    protected $paginationTheme = 'tailwind';

    public $name, $diagnosis_id;
    public $isEditing = false;
    public $search = '';
    public $sortField = 'name';
    public $sortAsc = true;
    public $selectedDiagnosisIds = [];
    public $selectAllPage = false;
    public $inlineEditingId = null;
    public $inlineName = '';

    // Import
    public $importFile = null;
    public $importResults = null;
    public $showImportPanel = false;

    protected function rules()
    {
        return [
            'name' => [
                'required', 'min:3', 'max:255', 
                Rule::unique('diagnoses', 'name')
                    ->where(fn ($query) => $query->where('clinic_id', Diagnosis::clinicIdForWrite()))
                    ->ignore($this->diagnosis_id)
            ],
        ];
    }

    public function updatingSearch()
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingPage()
    {
        $this->clearSelection();
        $this->cancelInlineEdit();
    }

    public function clearSearch()
    {
        $this->search = '';
        $this->resetPage();
    }

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortAsc = !$this->sortAsc;
        } else {
            $this->sortAsc = true;
        }
        $this->sortField = $field;
        $this->clearSelection();
    }

    public function updatedSelectAllPage($value)
    {
        if (!$value) {
            $this->selectedDiagnosisIds = [];
            return;
        }

        $this->selectedDiagnosisIds = $this->currentPageDiagnosisIds();
    }

    public function updatedSelectedDiagnosisIds()
    {
        $pageIds = $this->currentPageDiagnosisIds();
        $selected = array_map('intval', $this->selectedDiagnosisIds);
        $this->selectAllPage = !empty($pageIds) && empty(array_diff($pageIds, $selected));
    }

    public function clearSelection()
    {
        $this->selectedDiagnosisIds = [];
        $this->selectAllPage = false;
    }

    public function resetFields()
    {
        $this->name = '';
        $this->diagnosis_id = null;
        $this->isEditing = false;
        $this->resetValidation();
    }

    public function store()
    {
        $this->validate();
        Diagnosis::create(['name' => $this->name]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Diagnosis Created.']);
        $this->resetFields();
    }

    public function edit($id)
    {
        $diagnosis = Diagnosis::findOrFail($id);
        $this->diagnosis_id = $id;
        $this->name = $diagnosis->name;
        $this->isEditing = true;
    }

    public function startInlineEdit($id)
    {
        $diagnosis = Diagnosis::findOrFail($id);
        $this->inlineEditingId = $diagnosis->id;
        $this->inlineName = $diagnosis->name;
        $this->resetValidation('inlineName');
    }

    public function cancelInlineEdit()
    {
        $this->inlineEditingId = null;
        $this->inlineName = '';
        $this->resetValidation('inlineName');
    }

    public function saveInlineEdit()
    {
        if (!$this->inlineEditingId) {
            return;
        }

        $this->inlineName = trim($this->inlineName);

        $this->validate([
            'inlineName' => [
                'required',
                'min:3',
                'max:255',
                Rule::unique('diagnoses', 'name')
                    ->where(fn ($query) => $query->where('clinic_id', Diagnosis::clinicIdForWrite()))
                    ->ignore($this->inlineEditingId),
            ],
        ], [], [
            'inlineName' => 'diagnosis name',
        ]);

        Diagnosis::findOrFail($this->inlineEditingId)->update(['name' => $this->inlineName]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Diagnosis Updated.']);
        $this->cancelInlineEdit();
    }

    public function update()
    {
        $this->validate();
        Diagnosis::find($this->diagnosis_id)->update(['name' => $this->name]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Diagnosis Updated.']);
        $this->resetFields();
    }

    public function delete($id)
    {
        Diagnosis::find($id)->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Diagnosis Deleted.']);
        $this->clearSelection();
    }

    public function deleteSelected()
    {
        $ids = collect($this->selectedDiagnosisIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Select at least one diagnosis first.']);
            return;
        }

        $deleted = Diagnosis::whereIn('id', $ids)->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => $deleted . ' diagnosis record(s) deleted.']);
        $this->clearSelection();
    }

    public function export()
    {
        $fileName = 'diagnoses_export.csv';
        $diagnoses = Diagnosis::where('name', 'like', '%' . $this->search . '%')
            ->orderBy($this->sortField, $this->sortAsc ? 'asc' : 'desc')->get();

        $headers = ["Content-type" => "text/csv", "Content-Disposition" => "attachment; filename=$fileName"];
        $callback = function() use($diagnoses) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Name', 'Created At']);
            foreach ($diagnoses as $d) fputcsv($file, [$d->id, $d->name, $d->created_at]);
            fclose($file);
        };
        return Response::stream($callback, 200, $headers);
    }

    public function importCsv()
    {
        $this->validate([
            'importFile' => 'required|file|max:2048|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
        ], [
            'importFile.required'  => 'Please choose a CSV file.',
            'importFile.mimetypes' => 'The file must be a valid CSV (text/csv or .txt).',
            'importFile.max'       => 'File must be under 2 MB.',
        ]);

        $path = $this->importFile->getRealPath();
        $handle = fopen($path, 'r');

        // Skip header row
        fgetcsv($handle);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $rowNum   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            // Accept single-column (name) or two-column (id, name) formats
            $name = trim(count($row) > 1 ? ($row[1] ?? '') : ($row[0] ?? ''));

            if ($name === '') {
                $skipped++;
                continue;
            }

            if (mb_strlen($name) < 3) {
                $errors[] = "Row {$rowNum}: \"{$name}\" is too short (min 3 characters) — skipped.";
                $skipped++;
                continue;
            }

            if (mb_strlen($name) > 255) {
                $errors[] = "Row {$rowNum}: name exceeds 255 characters — skipped.";
                $skipped++;
                continue;
            }

            if (Diagnosis::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                $skipped++;
                continue;
            }

            Diagnosis::create(['name' => $name]);
            $imported++;
        }

        fclose($handle);

        $this->importFile      = null;
        $this->importResults   = compact('imported', 'skipped', 'errors');
        $this->showImportPanel = true;
    }

    public function clearImport()
    {
        $this->importFile      = null;
        $this->importResults   = null;
        $this->showImportPanel = false;
        $this->resetValidation('importFile');
    }

    public function downloadTemplate()
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="diagnoses_import_template.csv"',
        ];

        $callback = function () {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, ['name']);
            fputcsv($fh, ['Acanthamoeba Keratitis']);
            fputcsv($fh, ['Accommodative Esotropia']);
            fputcsv($fh, ['Acute Angle Closure Glaucoma']);
            fclose($fh);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function render()
    {
        return view('livewire.admin.diagnosis-component', [
            'diagnoses' => Diagnosis::where('name', 'like', '%' . $this->search . '%')
                ->orderBy($this->sortField, $this->sortAsc ? 'asc' : 'desc')
                ->paginate(15)
        ])->layout('layouts.admin.admin-layout');
    }

    private function currentPageDiagnosisIds(): array
    {
        return Diagnosis::where('name', 'like', '%' . $this->search . '%')
            ->orderBy($this->sortField, $this->sortAsc ? 'asc' : 'desc')
            ->paginate(15)
            ->getCollection()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }
}
