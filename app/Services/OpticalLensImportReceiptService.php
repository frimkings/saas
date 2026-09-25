<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\OpticalLensImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpticalLensImportReceiptService
{
    public function fingerprint(array $specs, array $quantities): string
    {
        unset($specs['sphere'], $specs['power']);
        ksort($specs);
        $normalized = [];
        foreach ($quantities as $r => $cells) foreach ($cells as $c => $quantity) {
            if ((int) $quantity > 0) $normalized[(int) $r.':'.(int) $c] = (int) $quantity;
        }
        ksort($normalized);
        return hash('sha256', json_encode([$specs, $normalized]));
    }

    public function matches(string $fingerprint)
    {
        return OpticalLensImport::where('fingerprint', $fingerprint)
            ->whereHas('movements', fn ($q) => $q->where('movement_type', 'receipt')->whereDoesntHave('reversedBy'));
    }

    public function receive(array $source, array $specs, array $lines, array $details, bool $updatePrice, string $repeatReason = ''): OpticalLensImport
    {
        return DB::transaction(function () use ($source, $specs, $lines, $details, $updatePrice, $repeatReason) {
            // Serialize duplicate checks and receipt commits within the branch.
            Branch::whereKey(OpticalLensImport::branchIdForWrite())->lockForUpdate()->firstOrFail();
            $fingerprint = $this->fingerprint($specs, $source['quantities']);
            $matches = $this->matches($fingerprint)->get();
            $reference = trim($details['reference'] ?? '');
            if ($matches->isNotEmpty() && (mb_strlen(trim($repeatReason)) < 10 || $reference === '' || $matches->contains(fn ($r) => mb_strtolower(trim($r->reference ?? '')) === mb_strtolower($reference)))) {
                throw ValidationException::withMessages(['importReceipt' => 'Already received as import #'.$matches->first()->id.'. For a separate delivery, enter a different invoice reference and a reason of at least 10 characters.']);
            }
            unset($specs['sphere'], $specs['power']);
            $receipt = OpticalLensImport::create([
                'user_id' => auth()->id(), 'filename' => $source['filename'], 'worksheet' => $source['worksheet'],
                'fingerprint' => $fingerprint, 'specifications' => $specs,
                'source_unit' => $source['unit'], 'source_quantity' => $source['source_quantity'],
                'pieces' => array_sum(array_column($lines, 2)), 'supplier' => $details['supplier'],
                'reference' => $reference ?: null, 'batch_number' => $details['batch_number'],
                'repeat_reason' => $matches->isNotEmpty() ? trim($repeatReason) : null,
            ]);
            foreach ($lines as [$sphere, $power, $quantity, $cost, $price]) {
                $lineSpecs = $specs + ['sphere' => number_format((float) $sphere, 2, '.', ''), 'power' => number_format((float) $power, 2, '.', '')];
                app(OpticalLensReceivingService::class)->receive($lineSpecs, $quantity, array_merge($details, [
                    'unit_cost' => round((float) $cost, 2), 'unit_price' => round((float) $price, 2),
                    'optical_lens_import_id' => $receipt->id,
                ]), $updatePrice);
            }
            return $receipt;
        });
    }

    public function reverse(int $id): void
    {
        DB::transaction(function () use ($id) {
            Branch::whereKey(OpticalLensImport::branchIdForWrite())->lockForUpdate()->firstOrFail();
            $receipt = OpticalLensImport::findOrFail($id);
            $movements = $receipt->movements()->where('movement_type', 'receipt')->whereDoesntHave('reversedBy')->orderBy('id')->get();
            if ($movements->isEmpty()) throw ValidationException::withMessages(['movement' => 'This import has already been fully reversed.']);
            foreach ($movements as $movement) app(OpticalStockLedgerService::class)->reverse($movement->id);
        });
    }
}
