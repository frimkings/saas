<?php

namespace App\Http\Middleware;

use App\Support\UploadLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire's temporary upload step has one size rule for the whole app, checked before any
 * page's own validation. Clinic users get the clinic limit there too, so an oversized file is
 * refused before it fills the server; platform admins keep room for the legacy SQL import.
 * Runs on Livewire's upload route (config/livewire.php → temporary_file_upload.middleware).
 */
class LimitTemporaryUploadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $kb = $request->user()?->is_platform_admin ? UploadLimits::PLATFORM_IMPORT_KB : UploadLimits::CLINIC_KB;
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.$kb]]);

        return $next($request);
    }
}
