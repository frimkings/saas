<?php

namespace App\Http\Controllers;

use App\Models\PlatformInvoice;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;

class ClinicBillingDocumentController extends Controller
{
    public function invoice(PlatformInvoice $invoice)
    {
        $this->authorizeInvoice($invoice);
        return Pdf::loadView('pdf.subscription-invoice',['invoice'=>$invoice->load(['clinic','payments']),'receipt'=>false])->stream($invoice->number.'.pdf');
    }

    public function receipt(PlatformInvoice $invoice)
    {
        $this->authorizeInvoice($invoice);abort_unless($invoice->status==='paid',404);
        return Pdf::loadView('pdf.subscription-invoice',['invoice'=>$invoice->load(['clinic','payments']),'receipt'=>true])->stream('Receipt-'.$invoice->number.'.pdf');
    }

    private function authorizeInvoice(PlatformInvoice $invoice): void
    {
        abort_unless(auth()->user()->hasRole('Super Admin'),403);
        abort_unless((int)$invoice->clinic_id===(int)app(TenantContext::class)->requireClinic()->id,404);
    }
}
