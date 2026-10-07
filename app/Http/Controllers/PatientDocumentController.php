<?php

namespace App\Http\Controllers;

use App\Models\PatientDocument;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientDocumentController extends Controller
{
    public function show(PatientDocument $document): StreamedResponse|Response
    {
        // Files once kept on a hosted server's own disk were lost when it was replaced on deploy.
        if (! Storage::disk($document->storage_disk)->exists($document->file_path)) {
            return response()->view('errors.404', [
                'heading' => 'Document file missing',
                'detail' => "The file for \"{$document->title}\" is no longer stored. Please upload it again from the patient's Documents tab.",
            ], 404);
        }

        return Storage::disk($document->storage_disk)->download(
            $document->file_path,
            $document->original_name,
            ['Content-Type' => $document->mime_type, 'Content-Disposition' => 'inline; filename="'.basename($document->original_name).'"']
        );
    }
}
