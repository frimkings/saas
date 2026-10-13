<?php

namespace App\Livewire\Optical;

/**
 * The lens stock grid on a page of its own (Optical → View Grid), so the grid has the whole
 * window. It is the catalogue's lens grid, unchanged: same ranges, reorder levels, power
 * selection and receiving links, without the catalogue's title and tabs around it.
 */
class OpticalLensGridComponent extends OpticalCatalogueComponent
{
    public $activeTab = 'lens-matrix';
    public bool $gridOnly = true;

    protected $queryString = ['matrixRange', 'matrixDesign', 'matrixForm', 'matrixDiameter', 'matrixIndex', 'matrixCoating', 'matrixEye'];

    /** Anything the grid links to in the catalogue (the price list, say) opens there. */
    public function setTab($tab)
    {
        return $this->redirectRoute('optical.catalogue', ['activeTab' => $tab], navigate: true);
    }
}
