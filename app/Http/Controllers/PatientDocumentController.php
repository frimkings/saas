<?php

namespace App\Http\Controllers;

use App\Models\PatientDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientDocumentController extends Controller
{
    public function show(PatientDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk($document->storage_disk)->exists($document->file_path), 404);
        return Storage::disk($document->storage_disk)->download(
            $document->file_path,
            $document->original_name,
            ['Content-Type' => $document->mime_type, 'Content-Disposition' => 'inline; filename="'.basename($document->original_name).'"']
        );
    }
}
