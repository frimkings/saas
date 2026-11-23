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
        {--set : Correct products that already have stock to the figure in the file (a recount)}
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

            $set = (bool) $this->option('set');
            $corrected = 0;
            foreach ($rows as [$name, $qty]) {
                $product = $products->get($key($name));
                $now = $product && ! $product->made_to_order ? $inventory->quantity($product) : 0;
                $status = match (true) {
                    ! $product => 'not found',
                    $qty === null => 'no figure in the file: left alone',
                    (bool) $product->made_to_order => 'made to order (stock not counted)',
                    $set && $now !== 0 && $now === max(0, $qty) => "already {$now}: nothing to change",
                    $set && $now !== 0 => 'recount',
                    $qty <= 0 => 'nothing to add',
                    $now !== 0 => "already has {$now} in stock: left alone (use --set to correct it)",
                    default => 'ok',
                };
                if ($status === 'ok') {
                    if ($commit) $this->book($inventory, $product, $qty, $note);
                    $booked++; $units += $qty;
                    $status = $commit ? 'booked' : 'will be booked';
                } elseif ($status === 'recount') {
                    $target = max(0, $qty);
                    if ($commit) $this->correct($inventory, $product, $now, $target, $note);
                    $corrected++;
                    $status = ($commit ? 'corrected' : 'will be corrected')." from {$now} to {$target}";
                }
                $report[] = [$name, $qty ?? '(blank)', $product?->id ?? '-', $status];
            }

            $this->table(['Name in file', 'Quantity', 'Product', 'Result'], $report);
            $this->info(($commit ? 'Booked' : 'Would book')." {$units} units across {$booked} products.");
            if ($set) $this->info(($commit ? 'Corrected' : 'Would correct')." {$corrected} products to the counted figure.");
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

    /** A recount: more than on record arrives as a delivery, less goes out as a "counted" movement. */
    private function correct(BranchInventoryService $inventory, Product $product, int $before, int $target, string $note): void
    {
        if ($target > $before) {
            $this->book($inventory, $product, $target - $before, $note);
            return;
        }
        DB::transaction(function () use ($inventory, $product, $target, $note) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $inventory->quantity($product);
            if ($before <= $target) return;
            do {
                $reference = 'COUNT-'.now()->format('Ymd-His').'-'.random_int(100, 999);
            } while (Stock::where('reference_no', $reference)->exists());
            $movement = Stock::create([
                'product_id' => $product->id,
                'user_id' => app(TenantContext::class)->user()->id,
                'reference_no' => $reference,
                'movement_type' => 'counted',
                'batch_number' => $product->batch_number,
                'quantity_before' => $before,
                'quantity' => $before - $target,
                'quantity_after' => $target,
                'cost_price' => $product->cost_price,
                'expiry_date' => $product->expiry_date,
                'notes' => $note,
            ]);
            $inventory->decrease($product, $before - $target);
            AuditTrail::record('stock.counted', "Stock count: {$product->name} corrected from {$before} to {$target} - {$reference}", $movement,
                ['quantity' => $before], ['quantity' => $target, 'reference_no' => $reference, 'note' => $note]);
        });
    }

    /** @return array<int, array{0: string, 1: ?int}>|null */
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
            // A blank figure means "not counted", never zero.
            $raw = preg_replace('/[^\d.\-]/', '', (string) ($row[$qtyCol] ?? ''));
            $rows[] = [$name, $raw === '' || $raw === '-' ? null : (int) round((float) $raw)];
        }
        fclose($handle);

        return $rows;
    }
}
