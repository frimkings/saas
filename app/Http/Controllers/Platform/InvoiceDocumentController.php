<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use Barryvdh\DomPDF\Facade\Pdf;

// Platform-side copies of the clinic invoice and receipt PDFs (same template, no tenant scoping).
class InvoiceDocumentController extends Controller
{
    public function invoice(PlatformInvoice $invoice)
    {
        return Pdf::loadView('pdf.subscription-invoice', ['invoice' => $invoice->load(['clinic', 'payments']), 'receipt' => false])->stream($invoice->number.'.pdf');
    }

    public function receipt(PlatformInvoice $invoice)
    {
        abort_unless($invoice->status === 'paid', 404);

        return Pdf::loadView('pdf.subscription-invoice', ['invoice' => $invoice->load(['clinic', 'payments']), 'receipt' => true])->stream('Receipt-'.$invoice->number.'.pdf');
    }
}
