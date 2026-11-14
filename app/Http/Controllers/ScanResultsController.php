<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\ScanOrder;
use App\Models\ScanOrderItem;
use App\Models\ScanPartnerClinic;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The page a referring clinic opens from the link it was sent: the scan order's result files.
 * No sign-in; the link is signed and expires (ScanResultsService::LINK_DAYS), and it only shows
 * results the patient agreed to share. Nothing here runs inside a clinic's session, so tenancy
 * scopes are lifted and every lookup is tied to the order's own clinic by hand.
 */
class ScanResultsController extends Controller
{
    public function show(Request $request, int $order)
    {
        $order = $this->order($order);
        $documents = PatientDocument::withoutGlobalScopes()->where('clinic_id', $order->clinic_id)->where('scan_order_id', $order->id)->orderBy('id')->get();
        $expires = Carbon::createFromTimestamp((int) $request->query('expires'));

        return response()->view('scans.results-page', [
            'order' => $order,
            'clinic' => Clinic::find($order->clinic_id),
            'patient' => Patient::withoutGlobalScopes()->where('clinic_id', $order->clinic_id)->find($order->patient_id),
            'partner' => ScanPartnerClinic::withoutGlobalScopes()->where('clinic_id', $order->clinic_id)->find($order->scan_partner_clinic_id),
            'items' => ScanOrderItem::withoutGlobalScopes()->where('scan_order_id', $order->id)->orderBy('id')->get(),
            'documents' => $documents,
            'fileUrl' => fn (PatientDocument $document) => URL::temporarySignedRoute('scan-results.file', $expires, ['order' => $order->id, 'document' => $document->id]),
            'expires' => $expires,
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function file(int $order, int $document)
    {
        $order = $this->order($order);
        $document = PatientDocument::withoutGlobalScopes()->where('clinic_id', $order->clinic_id)->where('scan_order_id', $order->id)->findOrFail($document);
        abort_unless(Storage::disk($document->storage_disk)->exists($document->file_path), 404);

        return Storage::disk($document->storage_disk)->download($document->file_path, $document->original_name,
            ['Content-Type' => $document->mime_type, 'Content-Disposition' => 'inline; filename="'.basename($document->original_name).'"', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /** Only a partner order the patient agreed to share, once reported. */
    private function order(int $id): ScanOrder
    {
        $order = ScanOrder::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($id);
        abort_unless($order->canShareResults(), 404);

        return $order;
    }
}
