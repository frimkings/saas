<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Stock;
use App\Services\Inventory\BranchInventoryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Books a clinic's counted stock into products that already exist, after a migration brought the products
 * across with zero stock (23 Nov 2026, Eyes of Hope from iTestEMR). Each count becomes an ordinary "received"
 * stock movement (reference OPEN-…, supplier "Opening stock"), so it shows in Stock Movements and the audit trail
 * like a delivery. A product that already has stock is left alone, so running the file twice changes nothing.
 *
 * The file is a CSV with a header row: a name column ("Name" or "Item Name") and a quantity column
 * ("Quantity", "Quantity Left" or "Counted"). Names match the clinic's products ignoring case and extra spaces.
 */
class SetOpeningStockCommand extends Command
{
    protected $signature = 'stock:opening {clinic : The clinic id} {file : CSV of product names and quantities}
        {--branch= : Branch id (default: the clinic\'s main branch)} {--note= : Note kept on each movement}
        {--commit : Book the stock (otherwise a dry run)}';

    protected $description = 'Book counted opening stock into existing products (dry run unless --commit)';

    public function handle(BranchInventoryService $inventory): int
    {
        $branch = $this->option('branch')
            ? Branch::withoutGlobalScopes()->where('clinic_id', $this->argument('clinic'))->find($this->option('branch'))
            : Branch::withoutGlobalScopes()->where('clinic_id', $this->argument('clinic'))->orderByDesc('is_default')->orderBy('id')->first();
        if (! $branch) {
            $this->error('No such clinic or branch.');
            return self::FAILURE;
        }
        $user = $branch->users()->wherePivot('status', 'active')->orderBy('users.id')->first();
        if (! $user) {
            $this->error('The branch has no active staff to record the movements under.');
            return self::FAILURE;
        }
        $rows = $this->readFile((string) $this->argument('file'));
        if ($rows === null) return self::FAILURE;

        $context = app(TenantContext::class);
        $branchIds = $user->branches()->where('branches.clinic_id', $branch->clinic_id)->wherePivot('status', 'active')
            ->pluck('branches.id')->map(fn ($id) => (int) $id)->all() ?: [$branch->id];
        $context->set($user, $branch->clinic, $branch, $branchIds);

        try {
            $commit = (bool) $this->option('commit');
            $note = $this->option('note') ?: 'Opening stock, counted figures brought in after migration';
            $this->info(($commit ? 'BOOKING' : 'DRY RUN').": {$branch->clinic->name} / {$branch->name}, recorded as {$user->name}");

            $key = fn (string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
            $products = Product::query()->get()->keyBy(fn (Product $p) => $key($p->name));
            $report = [];
            $booked = 0; $units = 0;

            foreach ($rows as [$name, $qty]) {
                $product = $products->get($key($name));
                $status = match (true) {
                    ! $product => 'not found',
                    $qty <= 0 => 'nothing to add',
                    (bool) $product->made_to_order => 'made to order (stock not counted)',
                    ($now = $inventory->quantity($product)) !== 0 => "already has {$now} in stock: left alone",
                    default => 'ok',
                };
                if ($status === 'ok') {
                    if ($commit) $this->book($inventory, $product, $qty, $note);
                    $booked++; $units += $qty;
                    $status = $commit ? 'booked' : 'will be booked';
                }
                $report[] = [$name, $qty, $product?->id ?? '-', $status];
            }

            $this->table(['Name in file', 'Quantity', 'Product', 'Result'], $report);
            $this->info(($commit ? 'Booked' : 'Would book')." {$units} units across {$booked} products.");
            if (! $commit) $this->comment('Nothing was changed. Run again with --commit to book it.');
        } finally {
            $context->clear();
        }

        return self::SUCCESS;
    }

    private function book(BranchInventoryService $inventory, Product $product, int $qty, string $note): void
    {
        DB::transaction(function () use ($inventory, $product, $qty, $note) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $inventory->quantity($product);
            do {
                $reference = 'OPEN-'.now()->format('Ymd-His').'-'.random_int(100, 999);
            } while (Stock::where('reference_no', $reference)->exists());

            $movement = Stock::create([
                'product_id' => $product->id,
                'user_id' => app(TenantContext::class)->user()->id,
                'reference_no' => $reference,
                'movement_type' => 'received',
                'supplier' => 'Opening stock',
                'batch_number' => $product->batch_number,
                'quantity_before' => $before,
                'quantity' => $qty,
                'quantity_after' => $before + $qty,
                'cost_price' => $product->cost_price,
                'manufacture_date' => $product->manufacture_date,
                'expiry_date' => $product->expiry_date,
                'notes' => $note,
            ]);
            $inventory->increase($product, $qty, [
                'batch_number' => $product->batch_number,
                'manufacture_date' => $product->manufacture_date,
                'expiry_date' => $product->expiry_date,
                'unit_cost' => $product->cost_price,
            ]);
            AuditTrail::record('stock.received', "Opening stock: {$qty} unit(s) of {$product->name} - {$reference}", $movement,
                ['quantity' => $before], ['quantity' => $before + $qty, 'received_quantity' => $qty, 'reference_no' => $reference, 'note' => $note]);
        });
    }

    /** @return array<int, array{0: string, 1: int}>|null */
    private function readFile(string $path): ?array
    {
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");
            return null;
        }
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $nameCol = array_search('name', $header, true);
        if ($nameCol === false) $nameCol = array_search('item name', $header, true);
        $qtyCol = false;
        foreach (['counted', 'quantity left', 'quantity'] as $q) {
            if (($qtyCol = array_search($q, $header, true)) !== false) break;
        }
        if ($nameCol === false || $qtyCol === false) {
            fclose($handle);
            $this->error('The file needs a header with a name column (Name or Item Name) and a quantity column (Quantity, Quantity Left or Counted).');
            return null;
        }
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$nameCol] ?? ''));
            if ($name === '') continue;
            $rows[] = [$name, (int) round((float) preg_replace('/[^\d.\-]/', '', (string) ($row[$qtyCol] ?? '')))];
        }
        fclose($handle);

        return $rows;
    }
}
