<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

class CleanupAbandonedCarts extends Command
{
    protected $signature = 'carts:cleanup-abandoned';
    protected $description = 'Remove abandoned carts in the active tenant branch.';

    public function handle(): int
    {
        Cart::cleanupAbandonedCarts();
        return self::SUCCESS;
    }
}
