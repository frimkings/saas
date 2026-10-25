<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class OfflinePasswordResetController extends Controller
{
    public function showRequestForm()
    {
        return view('auth.forgot-password');
    }

    public function findAccount(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $email = $request->email;

        // Fix H3: always perform the same work and return the same status
        // regardless of whether the email exists, to prevent user enumeration.
        $user = User::where('email', $email)->first();

        if ($user) {
            $existing = PasswordResetRequest::latestFor($email);

            if ($existing && $existing->isApproved()) {
                session(['offline_reset_email' => $email]);
                return redirect()->route('password.offline.form');
            }

            if (!$existing || !$existing->isPending()) {
                PasswordResetRequest::where('email', $email)
                    ->whereIn('status', ['rejected', 'completed'])
                    ->update(['status' => 'completed']);
                $resetRequest = PasswordResetRequest::create(['email' => $email, 'status' => 'pending']);
                app(\App\Services\OwnerAlerts::class)->passwordResetRequested($resetRequest);
            }
        }

        // Always show 'submitted' — attacker learns nothing about email existence
        return back()
            ->with('request_status', 'submitted')
            ->withInput();
    }

    public function showResetForm()
    {
        $email = session('offline_reset_email');

        if (!$email) {
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Please enter your email address first.']);
        }

        // Re-verify approval is still valid before showing the form
        $req = PasswordResetRequest::latestFor($email);
        if (!$req || !$req->isApproved()) {
            session()->forget('offline_reset_email');
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Your reset request is no longer approved. Please submit a new request.']);
        }

        return view('auth.offline-reset-password', ['email' => $email]);
    }

    public function reset(Request $request)
    {
        $email = session('offline_reset_email');

        if (!$email) {
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Session expired. Please start over.']);
        }

        $req = PasswordResetRequest::latestFor($email);
        if (!$req || !$req->isApproved()) {
            session()->forget('offline_reset_email');
            return redirect()->route('password.request')
                ->withErrors(['email' => 'Your reset approval has been revoked. Contact the administrator.']);
        }

        $request->validate([
            'code'                  => 'required|string|max:12',
            'password'              => ['required', 'confirmed', Password::min(6)],
            'password_confirmation' => 'required',
        ]);

        // The approval alone isn't enough: only the person the manager gave the code to can finish.
        $problem = $req->codeProblem();
        if ($problem !== null || !$req->acceptsCode($request->code)) {
            $closed = $req->fresh()->isRejected();
            if ($closed) session()->forget('offline_reset_email');
            $message = match (true) {
                $closed => 'Too many wrong codes. This request is closed; ask for a new reset.',
                $problem === 'none' => 'Ask your manager for your reset code: they get it under Approvals → Password resets.',
                $problem === 'expired' => 'That code has expired. Ask your manager for a new one.',
                default => 'That code is not right. Check it with your manager.',
            };
            return $closed
                ? redirect()->route('password.request')->withErrors(['email' => $message])
                : back()->withErrors(['code' => $message]);
        }

        User::where('email', $email)->firstOrFail()->update([
            'password' => Hash::make($request->password),
        ]);

        // Mark request completed so it can't be reused
        $req->update(['status' => 'completed', 'actioned_at' => now(), 'code_hash' => null]);
        session()->forget('offline_reset_email');

        return redirect()->route('login')
            ->with('status', 'Password reset successfully. You may now log in.');
    }
}
