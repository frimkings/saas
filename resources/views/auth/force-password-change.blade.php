<x-guest-layout>
    <div style="min-height:100vh;display:grid;place-items:center;background:#f4f7fb;padding:20px;font-family:Arial,sans-serif">
        <div style="width:min(440px,100%);background:#fff;border:1px solid #dce4ef;border-radius:14px;padding:30px;box-shadow:0 15px 40px rgba(15,52,96,.12)">
            <h1 style="margin:0;color:#0f3460;font-size:24px">Create your private password</h1>
            <p style="color:#64748b;line-height:1.5">The temporary password must be replaced before you can access clinic or platform information.</p>
            @if($errors->any())
                <div style="background:#fef2f2;color:#b91c1c;padding:12px;border-radius:8px;margin-bottom:16px">
                    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
            <form method="POST" action="{{ route('password.force.update') }}">
                @csrf
                @method('PUT')
                <label style="display:block;margin:14px 0 6px;font-weight:700">Temporary password</label>
                <input type="password" name="current_password" required autocomplete="current-password" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px">
                <label style="display:block;margin:14px 0 6px;font-weight:700">New password</label>
                <input type="password" name="password" required autocomplete="new-password" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px">
                <label style="display:block;margin:14px 0 6px;font-weight:700">Confirm new password</label>
                <input type="password" name="password_confirmation" required autocomplete="new-password" style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px">
                <button style="width:100%;margin-top:20px;padding:12px;border:0;border-radius:8px;background:#0d7377;color:#fff;font-weight:800;cursor:pointer">Save password and continue</button>
            </form>
            <form method="POST" action="{{ route('logout') }}" style="text-align:center;margin-top:14px">@csrf<button style="border:0;background:none;color:#64748b;cursor:pointer">Sign out</button></form>
        </div>
    </div>
</x-guest-layout>
