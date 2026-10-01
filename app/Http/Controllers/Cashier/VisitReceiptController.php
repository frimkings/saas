<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\PatientVisit;
use App\Models\Sales;
use App\Models\Setting;
use App\Services\Visits\PatientVisits;

/**
 * One receipt for a patient's whole clinical visit: interim ("part payment — balance due")
 * until the bill is settled, then final.
 */
class VisitReceiptController extends Controller
{
    public function show(PatientVisit $visit, PatientVisits $visits)
    {
        $visit->loadMissing('patient');

        return view('cashier.visit-receipt', $visits->summary($visit) + [
            'clinicSettings' => Setting::getSettings(),
        ]);
    }

    /** The visit receipt for a sale (sales from before visits existed join theirs on first print). */
    public function forSale(int $saleId, PatientVisits $visits)
    {
        $sale = Sales::clinicSales()->findOrFail($saleId);
        $visit = $visits->attach($sale);
        abort_unless($visit, 404, 'This sale has no patient, so it has no visit receipt.');

        return redirect()->route('cashier.visit-receipt.show', $visit);
    }
}
