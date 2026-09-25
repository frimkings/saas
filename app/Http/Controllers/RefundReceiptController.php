<?php

namespace App\Http\Controllers;

use App\Models\RefundLog;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

class RefundReceiptController extends Controller
{
    public function show(RefundLog $refund)
    {
        $this->authorizeReceipt($refund);

        return view('cashier.refund-receipt', $this->receiptData($refund));
    }

    public function downloadPdf(RefundLog $refund)
    {
        $this->authorizeReceipt($refund);
        $data = $this->receiptData($refund);

        return Pdf::loadView('cashier.refund-receipt', $data)
            ->setPaper([0, 0, 226.77, 841.89])
            ->download(($refund->refund_number ?: 'Refund') . '.pdf');
    }

    private function authorizeReceipt(RefundLog $refund): void
    {
        $refund->loadMissing('sale');
        $user = auth()->user();

        abort_unless($refund->status === RefundLog::STATUS_PROCESSED, 404);
        abort_unless(
            $user?->hasAnyRole(['Manager', 'Super Admin'])
                || $user?->can('manage billing')
                || $refund->initiated_by === $user?->id
                || $refund->sale?->user_id === $user?->id,
            403
        );
    }

    private function receiptData(RefundLog $refund): array
    {
        $refund->loadMissing([
            'sale.patient',
            'sale.user',
            'sale.items.product',
            'initiator',
            'approvedBy',
            'processedBy',
        ]);

        return [
            'refund' => $refund,
            'clinicSettings' => Setting::getSettings(),
        ];
    }
}
