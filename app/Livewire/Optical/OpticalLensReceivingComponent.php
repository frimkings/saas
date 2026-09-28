<?php

namespace App\Livewire\Optical;

/**
 * Full-page lens receiving for bulk grids and Excel lens orders. The receiving logic is
 * shared with the stock management modal; only the layout and the flow around it differ.
 */
class OpticalLensReceivingComponent extends OpticalStockManagementComponent
{
    protected bool $fullPage = true;

    public function mount(): void
    {
        $this->openReceipt();
        $this->stockType = 'lens';
        $this->entryMode = request()->query('entryMode') === 'single' ? 'single' : 'bulk';
        $this->fillLensSpecsFromQuery();
    }

    public function save(): void
    {
        $this->stockType = 'lens';
        $this->formType = 'receipt';
        parent::save();
        // The shared save closes the form only once the receipt is recorded.
        if ($this->showForm) return;
        session()->flash('lensMatrixUrl', route('optical.catalogue', ['activeTab' => 'lens-matrix', 'matrixRange' => $this->lensRange, 'matrixDesign' => $this->lensDesign, 'matrixIndex' => $this->lensIndex, 'matrixCoating' => $this->lensCoating, 'matrixDiameter' => $this->lensDiameter]));
        // Clear the entered grid first so the unsaved-changes guard lets the redirect through.
        $this->reset(['bulkQuantities', 'bulkCosts', 'bulkPrices', 'quantity', 'excelFile']);
        $this->dispatch('lens-receipt-saved');
        $this->redirectRoute('optical.stock');
    }

    public function viewImport(int $id): void
    {
        $this->redirectRoute('optical.stock', ['viewImport' => $id]);
    }

    public function render()
    {
        return view('livewire.optical.optical-lens-receiving-component', $this->receiptViewData())->layout('layouts.optical');
    }
}
