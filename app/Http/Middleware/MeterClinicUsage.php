<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use App\Support\Usage\UsageMeter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Measures each clinic request for Platform → Usage: page views and Livewire actions, data
 * sent and received, server time and database time. First in the web group so it wraps the
 * tenant middleware; it writes in terminate(), after the response has reached the browser.
 */
class MeterClinicUsage
{
    private const ATTRIBUTE = 'usage.meter';

    public function handle(Request $request, Closure $next): Response
    {
        if (! UsageMeter::enabled()) {
            return $next($request);
        }

        $meter = app(UsageMeter::class);
        $meter->reset();
        $started = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $response = $next($request);

        // Platform admins in platform mode, guests and the sign-in pages have no clinic.
        $clinicId = app(TenantContext::class)->clinicId();
        if ($clinicId) {
            [$dbMs, $dbQueries] = $meter->database();
            // Livewire sends the header empty, which some servers drop, so check the route too.
            $livewire = $request->hasHeader('X-Livewire') || $request->routeIs('livewire.update', '*.livewire.update');
            $request->attributes->set(self::ATTRIBUTE, [$clinicId, [
                'page_views' => ! $livewire && $request->isMethod('GET') ? 1 : 0,
                'actions' => $livewire ? 1 : 0,
                'bytes_out' => $this->responseBytes($response),
                'bytes_in' => (int) $request->header('Content-Length', 0),
                'server_ms' => (microtime(true) - $started) * 1000,
                'db_ms' => $dbMs,
                'db_queries' => $dbQueries,
            ]]);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($measured = $request->attributes->get(self::ATTRIBUTE)) {
            UsageMeter::record(...$measured);
        }
    }

    /** Size before the web server's compression, so it overstates the network data somewhat. */
    private function responseBytes(Response $response): int
    {
        if ($length = $response->headers->get('Content-Length')) {
            return (int) $length;
        }
        if ($response instanceof BinaryFileResponse) {
            return (int) ($response->getFile()->getSize() ?: 0);
        }
        if ($response instanceof StreamedResponse) {
            return 0;
        }

        return strlen((string) $response->getContent());
    }
}
