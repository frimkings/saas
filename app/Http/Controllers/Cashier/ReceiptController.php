<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Sales;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReceiptController extends Controller
{
    /**
     * Display the thermal receipt for printing
     *
     * @param int $saleId
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function show($saleId, Request $request)
    {
        $sale = $this->receiptSale($saleId);
        
        // Get change amount from query string (default to 0)
        $change = $request->query('change', 0);
        
        // Get clinic settings
        $clinicSettings = Setting::getSettings();
        
       
        return view('cashier.thermal-receipt', compact('sale', 'change', 'clinicSettings'));
    }

    public function downloadPdf($saleId, Request $request)
    {
        $sale = $this->receiptSale($saleId);
        $change = $request->query('change', 0);
        $clinicSettings = Setting::getSettings();

        $patientName = $sale->customer_display_name;
        $nameSlug = $patientName
            ? Str::slug($patientName)
            : Str::slug($sale->transaction_id);
        $date     = $sale->created_at->format('Y-m-d');
        $filename = "Receipt_{$nameSlug}_{$date}.pdf";

        return Pdf::loadView('cashier.thermal-receipt', compact('sale', 'change', 'clinicSettings'))
            ->setPaper([0, 0, 226.77, 841.89])
            ->download($filename);
    }

    private function receiptSale($saleId): Sales
    {
        $user  = auth()->user();
        $query = Sales::select(
                'id',
                'transaction_id',
                'total_amount',
                'amount_paid',
                'payment_status',
                'bill_status',
                'bill_version',
                'expires_at',
                'finalized_at',
                'finalized_by',
                'discount_type',
                'discount_value',
                'discount_amount',
                'discount_approved_by',
                'is_refunded',
                'patient_id',
                'customer_name',
                'customer_phone',
                'user_id',
                'created_at'
            )
            ->with([
                'items:id,sale_id,product_id,optical_product_id,dispensed_quantity,selling_price,subtotal',
                'items.product:id,name',
                'items.opticalProduct:id,name',
                'patient:id,name,contact,pxnumber',
                'user:id,name',
                'approvedBy:id,name',
                'paymentTransactions:id,sale_id,amount,payment_method,notes,collected_by,created_at',
                'adjustments:id,sale_id,type,amount,method,input_value,approved_by,created_by,reason,created_at',
            ]);

        // Cashiers can only view receipts for their own sales
        if ($user->hasRole('Cashier')) {
            $query->where('user_id', $user->id);
        }

        return $query->findOrFail($saleId);
    }
}
