<?php

namespace App\Http\Controllers;

use App\Models\ProspectQuote;
use App\Services\ProspectQuotes;

/** A prospect quote as a PDF: for the platform (by id), and for the prospect from the link they were sent (by token). */
class ProspectQuoteDocumentController extends Controller
{
    public function platform(ProspectQuote $quote, ProspectQuotes $quotes)
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);

        return $quotes->pdf($quote)->stream($quote->number . '.pdf');
    }

    public function public(string $token, ProspectQuotes $quotes)
    {
        abort_unless(config('tenancy.enabled'), 404);
        $quote = ProspectQuote::where('token', $token)->firstOrFail();

        return $quotes->pdf($quote)->stream($quote->number . '.pdf');
    }
}
