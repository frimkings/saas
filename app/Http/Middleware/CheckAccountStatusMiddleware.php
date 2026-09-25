<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAccountStatusMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return $next($request);
        }

        $user = auth()->user();

        if (!$user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->with('error', 'Your account has been deactivated.');
        }

        // Invalidate session when roles change so revoked access takes effect immediately
        $rolesHash = md5($user->roles->pluck('name')->sort()->implode(','));
        if ($request->session()->has('roles_hash') &&
            $request->session()->get('roles_hash') !== $rolesHash) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->with('error', 'Your account permissions have changed. Please log in again.');
        }
        $request->session()->put('roles_hash', $rolesHash);

        return $next($request);
    }


}
