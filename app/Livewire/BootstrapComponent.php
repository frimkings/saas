<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;

class BootstrapComponent extends Component
{
	use WithPagination;

	protected $paginationTheme = 'bootstrap';
}