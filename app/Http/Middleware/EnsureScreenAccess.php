<?php

namespace App\Http\Middleware;

use App\Support\Access\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `access`: opens a route only for people whose permissions allow it, looked up by route name
 * in App\Support\Access\Screens. Kept on Livewire updates too, so an action on a page is
 * refused once the person can no longer open that page.
 */
class EnsureScreenAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            Access::allowsRoute($request->user(), $request->route()?->getName()),
            403,
            'You do not have access to this page.'
        );

        return $next($request);
    }
}
