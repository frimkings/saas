<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Product;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marks a clinic's services as made to order, so they are never counted and the POS never blocks them at zero
 * stock (23 Nov 2026: the iTestEMR import brought Eyes of Hope's services across as stocked items at 0).
 * By default every product in a service-type category; --category=Name picks a category by name instead.
 * Any stock typed against them is cleared, with the old figures kept in the audit trail, as the Products page does.
 */
class MarkServicesMadeToOrderCommand extends Command
{
    protected $signature = 'products:services-made-to-order {clinic : The clinic id}
        {--category= : A category name instead of every service-type category}
        {--commit : Make the change (otherwise a dry run)}';

    protected $description = 'Mark a clinic\'s services as made to order so they sell without stock (dry run unless --commit)';

    public function handle(): int
    {
        $branch = Branch::withoutGlobalScopes()->where('clinic_id', $this->argument('clinic'))->orderByDesc('is_default')->orderBy('id')->first();
        if (! $branch) {
            $this->error('No such clinic.');
            return self::FAILURE;
        }
        $user = $branch->users()->wherePivot('status', 'active')->orderBy('users.id')->first();
        if (! $user) {
            $this->error('The clinic has no active staff to record the change under.');
            return self::FAILURE;
        }

        $context = app(TenantContext::class);
        $context->set($user, $branch->clinic, $branch, [$branch->id]);
        try {
            $commit = (bool) $this->option('commit');
            $category = $this->option('category');
            $products = Product::query()
                ->whereHas('category', fn ($q) => $category ? $q->where('name', $category) : $q->where('type', 'service'))
                ->with('category:id,name')->orderBy('name')->get();
            $this->info(($commit ? 'CHANGING' : 'DRY RUN').": {$branch->clinic->name}, ".($category ? "category \"{$category}\"" : 'service categories'));

            $report = [];
            $changed = 0;
            foreach ($products as $product) {
                if ($product->made_to_order) {
                    $report[] = [$product->name, $product->category?->name, 'already made to order'];
                    continue;
                }
                if ($commit) $this->markMadeToOrder($product);
                $changed++;
                $report[] = [$product->name, $product->category?->name, $commit ? 'now made to order' : 'will be made to order'];
            }

            $this->table(['Product', 'Category', 'Result'], $report);
            $this->info(($commit ? 'Changed' : 'Would change')." {$changed} of {$products->count()} products.");
            if (! $commit) $this->comment('Nothing was changed. Run again with --commit to make the change.');
        } finally {
            $context->clear();
        }

        return self::SUCCESS;
    }

    /** Same as ticking "made to order" on the Products page: stock is no longer counted, old figures kept in the audit trail. */
    private function markMadeToOrder(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $branchStock = $product->branchInventory()->withoutGlobalScopes()->where('quantity', '!=', 0)->pluck('quantity', 'branch_id')->all();
            $old = ['quantity' => (int) $product->quantity, 'branch_quantities' => $branchStock];

            $product->branchInventory()->withoutGlobalScopes()->update(['quantity' => 0]);
            $product->inventoryLots()->withoutGlobalScopes()->update(['quantity' => 0]);
            $product->forceFill(['quantity' => 0, 'made_to_order' => true])->save();

            AuditTrail::record('product.made_to_order', "{$product->name} set to made to order (services fix); stock of {$old['quantity']} cleared", $product,
                $old, ['quantity' => 0, 'made_to_order' => true], null, true);
        });
    }
}
