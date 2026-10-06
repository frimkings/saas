<div class="clinic-ui ui-page">
@php
    $user = auth()->user();

    $roleColor = match($roles->first()) {
        'Super Admin'                  => ['bg' => '#c0392b', 'light' => '#fadbd8'],
        'Manager'                      => ['bg' => '#8e44ad', 'light' => '#e8daef'],
        'Doctor'                       => ['bg' => '#1a7a4a', 'light' => '#d5f5e3'],
        'Secretary'                    => ['bg' => '#d68910', 'light' => '#fdebd0'],
        'Cashier'                      => ['bg' => '#1a5276', 'light' => '#d6eaf8'],
        default                        => ['bg' => '#2c3e50', 'light' => '#eaecee'],
    };

    $initials = collect(explode(' ', $name))
        ->filter()
        ->take(2)
        ->map(fn($w) => strtoupper($w[0]))
        ->implode('');

    $quickLinks = match(true) {
        $user->hasRole(['Super Admin','Manager']) => [
            ['label' => 'Admin Dashboard',  'route' => 'admin.dashboard',      'icon' => 'fas fa-tachometer-alt'],
            ['label' => 'User Management',  'route' => 'admin.users',           'icon' => 'fas fa-users-cog'],
            ['label' => 'Audit Trail',      'route' => 'admin.audit-trail',     'icon' => 'fas fa-history'],
            ['label' => 'Settings',         'route' => 'admin.settings',        'icon' => 'fas fa-cog'],
        ],
        $user->hasRole('Doctor') => [
            ['label' => 'Doctor Dashboard', 'route' => 'doctor.dashboard',      'icon' => 'fas fa-stethoscope'],
            ['label' => 'Awaiting Patients','route' => 'doctor.patient-awaiting','icon' => 'fas fa-user-clock'],
            ['label' => 'All Records',      'route' => 'doctor.all-records',    'icon' => 'fas fa-folder-open'],
            ['label' => 'Referrals',        'route' => 'doctor.referrals',      'icon' => 'fas fa-share-alt'],
        ],
        $user->hasRole('Secretary') => [
            ['label' => 'Sec. Dashboard',   'route' => 'secretary.dashboard',   'icon' => 'fas fa-tachometer-alt'],
            ['label' => 'Patients',         'route' => 'secretary.patients',    'icon' => 'fas fa-users'],
            ['label' => 'Appointments',     'route' => 'secretary.appointments','icon' => 'fas fa-calendar-check'],
            ['label' => 'Clearance',        'route' => 'secretary.patient-clearance','icon' => 'fas fa-check-circle'],
        ],
        default => [
            ['label' => 'POS / Seller Desk','route' => 'cashier.seller-desk',  'icon' => 'fas fa-cash-register'],
            ['label' => 'Sales Records',    'route' => 'cashier.sales-records', 'icon' => 'fas fa-receipt'],
            ['label' => 'Outstanding',      'route' => 'cashier.outstanding-balances','icon' => 'fas fa-file-invoice-dollar'],
        ],
    };
@endphp

{{-- ── Profile Cover ───────────────────────────────────────────────────── --}}
<div class="profile-cover" style="background:linear-gradient(135deg,{{ $roleColor['bg'] }} 0%,{{ $roleColor['bg'] }}cc 100%);min-height:160px;position:relative">
    <div class="w-full px-6 py-6">
        <div class="flex items-end" style="gap:1.25rem;padding-bottom:.5rem">
            {{-- Avatar --}}
            <div class="profile-avatar shadow-lg"
                 style="width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,.2);border:3px solid rgba(255,255,255,.6);display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden">
                @if($user->avatar_url)
                    <img src="{{ $user->avatar_url }}" alt="{{ $name }}" style="width:100%;height:100%;object-fit:cover">
                @else
                    {{ $initials }}
                @endif
            </div>
            {{-- Name / role --}}
            <div class="text-white">
                <h3 class="mb-0 font-semibold" style="text-shadow:0 1px 3px rgba(0,0,0,.3)">{{ $name }}</h3>
                <div class="mt-1" style="display:flex;gap:.4rem;flex-wrap:wrap">
                    @foreach($roles as $role)
                        <span style="background:rgba(255,255,255,.25);color:#fff;padding:.2rem .75rem;border-radius:50px;font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase">
                            {{ $role }}
                        </span>
                    @endforeach
                </div>
                <p class="mb-0 mt-1" style="font-size:.82rem;opacity:.8"><i class="fas fa-envelope mr-1"></i>{{ $email }}</p>
            </div>
        </div>
    </div>
</div>

{{-- ── Main content ─────────────────────────────────────────────────────── --}}
<div class="w-full px-6 py-6">
    <div class="flex flex-wrap -mx-2">

        {{-- Left: Tabs ───────────────────────────────── --}}
        <div class="w-full lg:w-8/12 px-2 mb-6">

            {{-- Tab nav --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-0" style="border-radius:10px;overflow:hidden">
                <div class="card-header border-b border-slate-200 py-2 bg-white border-b-0 pb-0 pt-4 px-4">
                    <ul class="flex flex-wrap" role="tablist" aria-label="Profile sections">
                        <li class="">
                            <button type="button" wire:click="switchTab('account')"
                                    aria-selected="{{ $activeTab === 'account' ? 'true' : 'false' }}" role="tab"
                                    class="block border-b-2 px-4 py-2 text-sm font-semibold {{ $activeTab === 'account' ? 'border-teal-700 text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                                <i class="fas fa-user-edit mr-1"></i> Account
                            </button>
                        </li>
                        <li class="">
                            <button type="button" wire:click="switchTab('security')"
                                    aria-selected="{{ $activeTab === 'security' ? 'true' : 'false' }}" role="tab"
                                    class="block border-b-2 px-4 py-2 text-sm font-semibold {{ $activeTab === 'security' ? 'border-teal-700 text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                                <i class="fas fa-shield-alt mr-1"></i> Security & Logins
                            </button>
                        </li>
                    </ul>
                </div>

                {{-- ── Account Tab ───────────────────────────── --}}
                @if($activeTab === 'account')
                <div class="card-body p-4 px-6 py-6">

                    <h6 class="uppercase text-slate-500 font-semibold mb-4" style="font-size:.72rem;letter-spacing:.08em">
                        Personal Information
                    </h6>

                    <div class="flex flex-wrap -mx-2">
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label class="text-sm font-semibold text-slate-500 mb-1">Full Name</label>
                            <input type="text"
                                   wire:model="name"
                                   class="form-control ui-input @error('name') is-invalid @enderror"
                                   placeholder="Your full name">
                            @error('name')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label class="text-sm font-semibold text-slate-500 mb-1">Email Address</label>
                            <div class="flex items-stretch">
                                <input type="email" value="{{ $email }}" class="form-control ui-input bg-slate-50" readonly>
                                <div class="flex">
                                    <span class="flex items-center border border-slate-300 px-2 text-sm bg-slate-50 text-slate-500" title="Contact admin to change"><i class="fas fa-lock"></i></span>
                                </div>
                            </div>
                            <small class="text-slate-500">Contact a Super Admin to change your email.</small>
                        </div>
                    </div>

                    <div class="flex flex-wrap -mx-2 mt-2">
                        <div class="w-full md:w-4/12 px-2 mb-4">
                            <label class="text-sm font-semibold text-slate-500 mb-1">Phone Number</label>
                            <div class="flex items-stretch">
                                <div class="flex">
                                    <span class="flex items-center border border-slate-300 px-2 text-sm text-slate-600 bg-slate-50"><i class="fas fa-phone text-slate-500"></i></span>
                                </div>
                                <input type="text"
                                       wire:model="phone"
                                       class="form-control ui-input @error('phone') is-invalid @enderror"
                                       placeholder="+63 912 345 6789">
                                @error('phone')<div class="ui-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="w-full md:w-4/12 px-2 mb-4">
                            <label class="text-sm font-semibold text-slate-500 mb-1">Gender</label>
                            <select wire:model="gender"
                                    class="ui-input @error('gender') is-invalid @enderror">
                                <option value="">— Not specified —</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                            @error('gender')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="w-full md:w-4/12 px-2 mb-4">
                            <label class="text-sm font-semibold text-slate-500 mb-1">Date of Birth</label>
                            <input type="date"
                                   wire:model="date_of_birth"
                                   class="form-control ui-input @error('date_of_birth') is-invalid @enderror">
                            @error('date_of_birth')<div class="ui-error">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <hr class="my-6">

                    <h6 class="uppercase text-slate-500 font-semibold mb-4" style="font-size:.72rem;letter-spacing:.08em">
                        Profile Photo
                    </h6>
                    <div class="flex items-start mb-4" style="gap:1rem">
                        {{-- Current avatar preview --}}
                        <div style="width:64px;height:64px;border-radius:50%;overflow:hidden;background:{{ $roleColor['bg'] }};border:2px solid #dee2e6;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;color:#fff;flex-shrink:0">
                            @if($avatar)
                                <img src="{{ $avatar->temporaryUrl() }}" style="width:100%;height:100%;object-fit:cover" alt="Preview">
                            @elseif($user->avatar_url)
                                <img src="{{ $user->avatar_url }}" style="width:100%;height:100%;object-fit:cover" alt="Avatar">
                            @else
                                {{ $initials }}
                            @endif
                        </div>
                        <div class="grow">
                            <div class="mb-2">
                                <label class="btn ui-button ui-button-secondary ui-button-sm mb-0" style="cursor:pointer">
                                    <i class="fas fa-upload mr-1"></i> Choose Photo
                                    <input type="file" wire:model.live="avatar" accept="image/*" style="display:none">
                                </label>
                                @if($user->avatar)
                                <button type="button" wire:click="removeAvatar" class="btn ui-button ui-button-danger ui-button-sm ml-1"
                                        wire:confirm="Remove your profile photo?">
                                    <i class="fas fa-trash mr-1"></i> Remove
                                </button>
                                @endif
                            </div>
                            @error('avatar') <div class="text-sm text-red-700">{{ $message }}</div> @enderror
                            <small class="text-slate-500">JPG, PNG or GIF · max 2 MB</small>
                            <div wire:loading wire:target="avatar" class="text-sm text-sky-700 mt-1"><i class="fas fa-spinner fa-spin mr-1"></i> Uploading…</div>
                        </div>
                    </div>

                    <hr class="my-6">

                    <h6 class="uppercase text-slate-500 font-semibold mb-1" style="font-size:.72rem;letter-spacing:.08em">
                        Change Password
                    </h6>
                    <p class="text-slate-500 text-sm mb-4">Leave blank to keep your current password.</p>

                    <div class="flex flex-wrap -mx-2">
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label class="text-sm font-semibold text-slate-500 mb-1">New Password</label>
                            <div class="flex items-stretch">
                                <input type="password"
                                       id="pwField"
                                       wire:model="password"
                                       class="form-control ui-input @error('password') is-invalid @enderror"
                                       placeholder="Min 8 characters"
                                       oninput="updateStrength(this.value)">
                                <div class="flex">
                                    <button type="button" class="btn ui-button ui-button-secondary" onclick="togglePw('pwField',this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('password')<div class="ui-error">{{ $message }}</div>@enderror
                            </div>
                            {{-- Strength bar --}}
                            <div class="mt-2" id="strengthWrap" style="display:none">
                                <div class="h-2 overflow-hidden rounded-full bg-slate-200" style="height:5px;border-radius:3px">
                                    <div id="strengthBar" class="h-full bg-teal-600" style="width:0;transition:.3s"></div>
                                </div>
                                <small id="strengthLabel" class="text-slate-500"></small>
                            </div>
                        </div>
                        <div class="w-full md:w-6/12 px-2 mb-4">
                            <label class="text-sm font-semibold text-slate-500 mb-1">Confirm New Password</label>
                            <div class="flex items-stretch">
                                <input type="password"
                                       id="pwConfirm"
                                       wire:model="password_confirmation"
                                       class="form-control ui-input"
                                       placeholder="Repeat password">
                                <div class="flex">
                                    <button type="button" class="btn ui-button ui-button-secondary" onclick="togglePw('pwConfirm',this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end mt-4">
                        <button type="button" wire:click="updateProfile"
                                class="btn ui-button ui-button-primary px-6 font-semibold shadow-sm"
                                style="background:{{ $roleColor['bg'] }};border-color:{{ $roleColor['bg'] }}">
                            <span wire:loading.remove wire:target="updateProfile"><i class="fas fa-save mr-2"></i>Save Changes</span>
                            <span wire:loading wire:target="updateProfile"><i class="fas fa-spinner fa-spin mr-2"></i>Saving…</span>
                        </button>
                    </div>
                </div>

                {{-- ── Security Tab ───────────────────────────── --}}
                @else
                <div class="card-body p-4 px-6 py-6">

                    <h6 class="uppercase text-slate-500 font-semibold mb-4" style="font-size:.72rem;letter-spacing:.08em">
                        Recent Login Activity
                    </h6>

                    @if($loginLogs->isNotEmpty())
                    <div class="ui-table-wrap">
                        <table class="table ui-table ui-table-sm">
                            <thead class="">
                                <tr>
                                    <th><i class="fas fa-clock mr-1 text-slate-500"></i>Date &amp; Time</th>
                                    <th><i class="fas fa-network-wired mr-1 text-slate-500"></i>IP Address</th>
                                    <th><i class="fas fa-desktop mr-1 text-slate-500"></i>Device / Browser</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($loginLogs as $log)
                                @php
                                    $ua  = $log->user_agent ?? '';
                                    $browser = match(true) {
                                        str_contains($ua,'Chrome') && !str_contains($ua,'Edg') => 'Chrome',
                                        str_contains($ua,'Firefox') => 'Firefox',
                                        str_contains($ua,'Safari') && !str_contains($ua,'Chrome') => 'Safari',
                                        str_contains($ua,'Edg')    => 'Edge',
                                        str_contains($ua,'Opera')  => 'Opera',
                                        default                    => 'Unknown',
                                    };
                                    $device = match(true) {
                                        str_contains($ua,'Mobile')  => 'Mobile',
                                        str_contains($ua,'Tablet')  => 'Tablet',
                                        default                     => 'Desktop',
                                    };
                                    $deviceIcon = match($device) {
                                        'Mobile' => 'fas fa-mobile-alt',
                                        'Tablet' => 'fas fa-tablet-alt',
                                        default  => 'fas fa-desktop',
                                    };
                                @endphp
                                <tr>
                                    <td class="align-middle">
                                        <div class="font-semibold">{{ $log->login_at->format('d M Y') }}</div>
                                        <small class="text-slate-500">{{ $log->login_at->format('H:i') }} · {{ $log->login_at->diffForHumans() }}</small>
                                    </td>
                                    <td class="align-middle">
                                        <code class="text-sm">{{ $log->ip_address ?? '—' }}</code>
                                    </td>
                                    <td class="align-middle">
                                        <i class="{{ $deviceIcon }} mr-1 text-slate-500"></i>
                                        {{ $browser }} / {{ $device }}
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">Successful</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                        <div class="text-center text-slate-500 py-6">
                            <i class="fas fa-history fa-2x mb-2 block"></i>No login history found.
                        </div>
                    @endif

                    <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 border-0 mt-4" style="border-radius:8px">
                        <i class="fas fa-info-circle mr-2"></i>
                        If you notice any unrecognised logins, change your password immediately and contact your system administrator.
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- Right: Sidebar ───────────────────────────── --}}
        <div class="w-full lg:w-4/12 px-2">

            {{-- Stats Card --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-4" style="border-radius:10px;overflow:hidden">
                <div class="card-header border-b font-semibold uppercase text-sm text-slate-500 border-slate-200 bg-white py-2 px-4"
                     style="letter-spacing:.06em">
                    Account Overview
                </div>
                <div class="card-body p-4 px-4 py-4">
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-calendar-plus mr-2 text-teal-700"></i>Member Since</span>
                        <span class="text-sm font-semibold">{{ $user->created_at->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-clock mr-2 text-sky-700"></i>Account Age</span>
                        <span class="text-sm font-semibold">{{ $accountAge }} days</span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-sign-in-alt mr-2 text-green-700"></i>Total Logins</span>
                        <span class="text-sm font-semibold">{{ $totalLogins }}</span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-history mr-2 text-amber-600"></i>Last Login</span>
                        <span class="text-sm font-semibold">
                            @if($lastLogin)
                                {{ $lastLogin->login_at->format('d M, H:i') }}
                            @else
                                First session
                            @endif
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-slate-500"><i class="fas fa-circle mr-2 {{ $user->is_active ? 'text-green-700' : 'text-red-700' }}"></i>Status</span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $user->is_active ? 'success' : 'danger' }} px-2">
                            {{ $user->is_active ? 'Active' : 'Suspended' }}
                        </span>
                    </div>
                </div>
                {{-- Mini stat bar --}}
                <div class="border-t border-slate-200 py-2 bg-white border-0 pt-0 px-4 pb-4">
                    <div class="flex flex-wrap -mx-2 text-center">
                        <div class="w-4/12 px-2 border-r border-slate-200">
                            <div class="font-semibold text-teal-700" style="font-size:1.1rem">{{ $totalLogins }}</div>
                            <div class="text-slate-500" style="font-size:.7rem">Logins</div>
                        </div>
                        <div class="w-4/12 px-2 border-r border-slate-200">
                            <div class="font-semibold text-green-700" style="font-size:1.1rem">{{ $accountAge }}</div>
                            <div class="text-slate-500" style="font-size:.7rem">Days</div>
                        </div>
                        <div class="w-4/12 px-2">
                            <div class="font-semibold" style="font-size:1.1rem;color:{{ $roleColor['bg'] }}">
                                {{ $roles->count() }}
                            </div>
                            <div class="text-slate-500" style="font-size:.7rem">{{ Str::plural('Role', $roles->count()) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Staff Info Card --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-4" style="border-radius:10px;overflow:hidden">
                <div class="card-header border-b font-semibold uppercase text-sm text-slate-500 border-slate-200 bg-white py-2 px-4"
                     style="letter-spacing:.06em">
                    <i class="fas fa-id-card mr-1"></i> Staff Details
                </div>
                <div class="card-body p-4 px-4 py-4">
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-barcode mr-2 text-slate-500"></i>Staff ID</span>
                        <span class="text-sm font-semibold font-monospace">{{ $staff_id ?: '—' }}</span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-building mr-2 text-slate-500"></i>Department</span>
                        <span class="text-sm font-semibold">{{ $department ?: '—' }}</span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-calendar-check mr-2 text-slate-500"></i>Hire Date</span>
                        <span class="text-sm font-semibold">
                            {{ $hire_date ? \Carbon\Carbon::parse($hire_date)->format('d M Y') : '—' }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-briefcase mr-2 text-slate-500"></i>Service</span>
                        <span class="text-sm font-semibold">{{ $user->service_length }}</span>
                    </div>
                    @if($user->date_of_birth)
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-slate-500"><i class="fas fa-birthday-cake mr-2 text-slate-500"></i>Age</span>
                        <span class="text-sm font-semibold">{{ $user->age }} yrs</span>
                    </div>
                    @endif
                    @if($phone)
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-slate-500"><i class="fas fa-phone mr-2 text-slate-500"></i>Phone</span>
                        <a href="tel:{{ $phone }}" class="text-sm font-semibold text-slate-900">{{ $phone }}</a>
                    </div>
                    @endif
                </div>
                <div class="border-t border-slate-200 bg-slate-50 border-0 py-2 px-4">
                    <small class="text-slate-500"><i class="fas fa-lock mr-1"></i> Staff details are managed by admin.</small>
                </div>
            </div>

            {{-- Roles Card --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm mb-4" style="border-radius:10px;overflow:hidden">
                <div class="card-header border-b font-semibold uppercase text-sm text-slate-500 border-slate-200 bg-white py-2 px-4"
                     style="letter-spacing:.06em">
                    <i class="fas fa-id-badge mr-1"></i> Access Roles
                </div>
                <div class="card-body p-4 px-4 py-4">
                    @foreach($roles as $role)
                    @php
                        $rColor = match($role) {
                            'Super Admin' => '#c0392b',
                            'Manager'     => '#8e44ad',
                            'Doctor'      => '#1a7a4a',
                            'Secretary'   => '#d68910',
                            'Cashier'     => '#1a5276',
                            default       => '#2c3e50',
                        };
                        $rIcon = match($role) {
                            'Super Admin' => 'fas fa-crown',
                            'Manager'     => 'fas fa-briefcase',
                            'Doctor'      => 'fas fa-stethoscope',
                            'Secretary'   => 'fas fa-user-tie',
                            'Cashier'     => 'fas fa-cash-register',
                            default       => 'fas fa-user',
                        };
                    @endphp
                    <div class="flex items-center p-2 mb-2 rounded-md"
                         style="background:{{ $rColor }}18;border-left:3px solid {{ $rColor }}">
                        <i class="{{ $rIcon }} mr-2" style="color:{{ $rColor }};width:16px"></i>
                        <span class="font-semibold text-sm" style="color:{{ $rColor }}">{{ $role }}</span>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Quick Links --}}
            <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
                <div class="card-header border-b font-semibold uppercase text-sm text-slate-500 border-slate-200 bg-white py-2 px-4"
                     style="letter-spacing:.06em">
                    <i class="fas fa-bolt mr-1"></i> Quick Links
                </div>
                <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                    @foreach($quickLinks as $link)
                        @if(\Illuminate\Support\Facades\Route::has($link['route']))
                        <a href="{{ route($link['route']) }}"
                           class="list-group-item block w-full border-b border-slate-100 text-left hover:bg-slate-50 flex items-center py-2 px-4 text-sm">
                            <i class="{{ $link['icon'] }} mr-2 text-slate-500" style="width:16px"></i>
                            {{ $link['label'] }}
                            <i class="fas fa-chevron-right ml-auto text-slate-500" style="font-size:.65rem"></i>
                        </a>
                        @endif
                    @endforeach
                    <a href="{{ route('staff.messages') }}"
                       class="list-group-item block w-full border-b border-slate-100 text-left hover:bg-slate-50 flex items-center py-2 px-4 text-sm">
                        <i class="far fa-envelope mr-2 text-slate-500" style="width:16px"></i>
                        Messages
                        <i class="fas fa-chevron-right ml-auto text-slate-500" style="font-size:.65rem"></i>
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function togglePw(id, btn) {
    var f = document.getElementById(id);
    if (!f) return;
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}
function updateStrength(val) {
    var wrap = document.getElementById('strengthWrap');
    var bar  = document.getElementById('strengthBar');
    var lbl  = document.getElementById('strengthLabel');
    if (!wrap || !bar || !lbl) return;
    if (!val) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    var score = 0;
    if (val.length >= 8)            score++;
    if (/[A-Z]/.test(val))          score++;
    if (/[0-9]/.test(val))          score++;
    if (/[^A-Za-z0-9]/.test(val))   score++;
    var configs = [
        { w: '25%', cls: 'bg-red-600',   text: 'Weak' },
        { w: '50%', cls: 'bg-amber-500', text: 'Fair' },
        { w: '75%', cls: 'bg-sky-600',   text: 'Good' },
        { w: '100%',cls: 'bg-green-600', text: 'Strong' },
    ];
    var c = configs[score - 1] || configs[0];
    bar.style.width = c.w;
    bar.className   = 'h-full transition-all ' + c.cls;
    lbl.textContent = c.text;
}
</script>

<style>
.profile-cover { margin:-1rem -1rem 0; }
.font-weight-semibold { font-weight: 600; }
.list-group-item-action:hover { background:#f8f9fa; }
</style>
</div>
