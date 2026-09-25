<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutInactiveUsers
{
    private const LAST_ACTIVITY_KEY = 'auth.last_activity_at';
    private const TIMEOUT_SECONDS = 30 * 60;

    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $lastActivity = (int) $request->session()->get(self::LAST_ACTIVITY_KEY, time());

        if ((time() - $lastActivity) >= self::TIMEOUT_SECONDS) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Session expired due to inactivity.',
                ], 419);
            }

            return redirect()
                ->route('login')
                ->with('error', 'You were logged out after 30 minutes of inactivity.');
        }

        $request->session()->put(self::LAST_ACTIVITY_KEY, time());

        return $next($request);
    }
}
