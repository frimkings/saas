<?php

namespace App\Livewire\Cashier;

use Livewire\Component;

class SellerDeskComponent extends Component
{
    public function mount(): void
    {
        abort(404);
    }

    public function render()
    {
        return view('livewire.cashier.seller-desk-component')
            ->layout('layouts.secretary.secretary-layout');
    }
}
