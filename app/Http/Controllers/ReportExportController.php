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
        $search = $request->input('search');
        $productId = $request->input('product_id');
        $categoryId = $request->input('category_id');
        $showRefunded = $request->boolean('show_refunded');
        $trash = $request->boolean('trash');

        $sales = Sales::clinicSales()->with(['items.product.category', 'patient:id,name', 'user:id,name'])
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($trash, function ($q) {
                $q->where('is_refunded', true);
            }, function ($q) use ($showRefunded) {
                if (!$showRefunded) {
                    $q->where('is_refunded', false);
                }
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($query) use ($search) {
                    $query->where('transaction_id', 'like', '%' . $search . '%')
                        ->orWhereHas('patient', function ($patient) use ($search) {
                            $patient->where('name', 'like', '%' . $search . '%');
                        })
                        ->orWhereHas('user', function ($user) use ($search) {
                            $user->where('name', 'like', '%' . $search . '%');
                        });
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
            ->get();

        $data = [
            'sales' => $sales,
            'from' => $from,
            'to' => $to,
            'generated_at' => now(),
            'clinicSettings' => Setting::getSettings(),
            'filters' => [
                'search' => $search,
                'product_id' => $productId,
                'category_id' => $categoryId,
                'show_refunded' => $showRefunded,
                'trash' => $trash,
            ],
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.pdf-sales', $data);
        $filename = "sales-report-{$from}-to-{$to}.pdf";

        return $pdf->download($filename);
    }
}
