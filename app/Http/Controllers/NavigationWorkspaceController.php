<?php

namespace App\Http\Controllers;

use App\Support\NavigationWorkspace;
use Illuminate\Http\Request;

class NavigationWorkspaceController extends Controller
{
    public function __invoke(Request $request)
    {
        $choice = $request->validate(['workspace' => 'required|in:administration,clinical,optical'])['workspace'];
        $available = NavigationWorkspace::available($request->user());
        abort_unless(isset($available[$choice]), 403);
        $key = NavigationWorkspace::preferenceKey($request->user());
        $request->session()->put($key, $choice);

        return redirect()->route($available[$choice][1])
            ->withCookie(cookie($key, $choice, 60 * 24 * 365));
    }
}
