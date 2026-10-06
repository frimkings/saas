<?php

namespace App\Http\Controllers;

use App\Models\Sales;
use App\Models\Setting;
use Illuminate\Http\Request;

class ReportExportController extends Controller
{
    public function exportPdf(Request $request)
    {
        // accept from/to in query or default last 30 days
        $from = $request->input('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->input('to', now()->format('Y-m-d'));
        $search = trim((string) $request->input('search', ''));
        $productId = $request->input('product_id');
        $categoryId = $request->input('category_id');
        $showRefunded = $request->boolean('show_refunded');
        $trash = $request->boolean('trash');
        // Same filters as the Sales Reports screen, so the PDF matches what staff see.
        $paymentStatus = in_array($request->input('payment_status'), ['paid', 'partial', 'unpaid'], true) ? $request->input('payment_status') : '';
        $purchaseType = in_array($request->input('purchase_type'), ['patient', 'direct'], true) ? $request->input('purchase_type') : '';
        $insurance = in_array($request->input('insurance'), ['insured', 'uninsured'], true) ? $request->input('insurance') : '';

        $sales = Sales::clinicSales()->with(['items.product.category', 'patient:id,name', 'user:id,name'])
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($trash, function ($q) {
                $q->where('is_refunded', true);
            }, function ($q) use ($showRefunded) {
                if (!$showRefunded) {
                    $q->where('is_refunded', false);
                }
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($query) use ($search) {
                    $query->where('transaction_id', 'like', '%' . $search . '%')
                        ->orWhere('customer_name', 'like', '%' . $search . '%')
                        ->orWhereHas('patient', fn ($patient) => $patient->where('name', 'like', '%' . $search . '%'));
                });
            })
            ->when($productId, function ($q) use ($productId) {
                $q->whereHas('items', function ($items) use ($productId) {
                    $items->where('product_id', $productId);
                });
            })
            ->when($categoryId, function ($q) use ($categoryId) {
                $q->whereHas('items.product', function ($product) use ($categoryId) {
                    $product->where('category_id', $categoryId);
                });
            })
            ->when(!$trash && $purchaseType === 'patient', fn ($q) => $q->whereNotNull('patient_id'))
            ->when(!$trash && $purchaseType === 'direct', fn ($q) => $q->whereNull('patient_id'))
            ->when(!$trash && $paymentStatus, fn ($q) => $q->where('payment_status', $paymentStatus))
            ->when(!$trash && $insurance === 'insured', fn ($q) => $q->where('insurer_amount', '>', 0))
            ->when(!$trash && $insurance === 'uninsured', fn ($q) => $q->where('insurer_amount', '<=', 0))
            ->orderBy('created_at')
            ->get();

        $applied = array_values(array_filter([
            $trash ? 'Refunded transactions only' : ($showRefunded ? 'Refunds included' : null),
            $search !== '' ? 'Search: "' . $search . '"' : null,
            !$trash && $paymentStatus ? 'Payment: ' . ucfirst($paymentStatus) : null,
            !$trash && $purchaseType ? 'Purchase: ' . ($purchaseType === 'patient' ? 'Patient' : 'Walk-in') : null,
            !$trash && $insurance ? ($insurance === 'insured' ? 'Insured bills only' : 'Uninsured bills only') : null,
        ]));

        $data = [
            'sales' => $sales,
            'from' => $from,
            'to' => $to,
            'generated_at' => now(),
            'clinicSettings' => Setting::getSettings(),
            'applied' => $applied,
            'filters' => [
                'search' => $search,
                'product_id' => $productId,
                'category_id' => $categoryId,
                'show_refunded' => $showRefunded,
                'trash' => $trash,
                'payment_status' => $paymentStatus,
                'purchase_type' => $purchaseType,
                'insurance' => $insurance,
            ],
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.pdf-sales', $data);
        $filename = "sales-report-{$from}-to-{$to}.pdf";

        return $pdf->download($filename);
    }
}
