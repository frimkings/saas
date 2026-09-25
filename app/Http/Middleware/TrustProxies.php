<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    // Fix #6: null = trust NO proxy headers at all.
    // If this app is ever deployed behind a known reverse proxy (e.g. Nginx, AWS ALB),
    // set this to the proxy's specific IP(s), NEVER '*'.
    // Example: protected $proxies = ['10.0.0.1'];
    protected $proxies = null;

    // Restrict to only the headers a legitimate proxy would set.
    // AWS ELB header removed — not relevant for this deployment.
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;
}
