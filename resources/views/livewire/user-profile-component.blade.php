<div>
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
    <div class="container-fluid px-4 py-4">
        <div class="d-flex align-items-end" style="gap:1.25rem;padding-bottom:.5rem">
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
                <h3 class="mb-0 font-weight-bold" style="text-shadow:0 1px 3px rgba(0,0,0,.3)">{{ $name }}</h3>
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
<div class="container-fluid px-4 py-4">
    <div class="row">

        {{-- Left: Tabs ───────────────────────────────── --}}
        <div class="col-lg-8 mb-4">

            {{-- Tab nav --}}
            <div class="card border-0 shadow-sm mb-0" style="border-radius:10px;overflow:hidden">
                <div class="card-header bg-white border-bottom-0 pb-0 pt-3 px-3">
                    <ul class="nav nav-tabs border-0">
                        <li class="nav-item">
                            <button type="button" wire:click="switchTab('account')"
                                    class="nav-link btn btn-link font-weight-semibold {{ $activeTab === 'account' ? 'active border-bottom-0' : 'text-muted' }}"
                                    style="border-radius:6px 6px 0 0">
                                <i class="fas fa-user-edit mr-1"></i> Account
                            </button>
                        </li>
                        <li class="nav-item">
                            <button type="button" wire:click="switchTab('security')"
                                    class="nav-link btn btn-link font-weight-semibold {{ $activeTab === 'security' ? 'active border-bottom-0' : 'text-muted' }}"
                                    style="border-radius:6px 6px 0 0">
                                <i class="fas fa-shield-alt mr-1"></i> Security & Logins
                            </button>
                        </li>
                    </ul>
                </div>

                {{-- ── Account Tab ───────────────────────────── --}}
                @if($activeTab === 'account')
                <div class="card-body px-4 py-4">

                    <h6 class="text-uppercase text-muted font-weight-bold mb-3" style="font-size:.72rem;letter-spacing:.08em">
                        Personal Information
                    </h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small font-weight-bold text-muted mb-1">Full Name</label>
                            <input type="text"
                                   wire:model="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="Your full name">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small font-weight-bold text-muted mb-1">Email Address</label>
                            <div class="input-group">
                                <input type="email" value="{{ $email }}" class="form-control bg-light" readonly>
                                <div class="input-group-append">
                                    <span class="input-group-text bg-light text-muted" title="Contact admin to change"><i class="fas fa-lock"></i></span>
                                </div>
                            </div>
                            <small class="text-muted">Contact a Super Admin to change your email.</small>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-4 mb-3">
                            <label class="small font-weight-bold text-muted mb-1">Phone Number</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-light"><i class="fas fa-phone text-muted"></i></span>
                                </div>
                                <input type="text"
                                       wire:model="phone"
                                       class="form-control @error('phone') is-invalid @enderror"
                                       placeholder="+63 912 345 6789">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="small font-weight-bold text-muted mb-1">Gender</label>
                            <select wire:model="gender"
                                    class="custom-select @error('gender') is-invalid @enderror">
                                <option value="">— Not specified —</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                            @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="small font-weight-bold text-muted mb-1">Date of Birth</label>
                            <input type="date"
                                   wire:model="date_of_birth"
                                   class="form-control @error('date_of_birth') is-invalid @enderror">
                            @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-uppercase text-muted font-weight-bold mb-3" style="font-size:.72rem;letter-spacing:.08em">
                        Profile Photo
                    </h6>
                    <div class="d-flex align-items-start mb-3" style="gap:1rem">
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
                        <div class="flex-grow-1">
                            <div class="mb-2">
                                <label class="btn btn-outline-secondary btn-sm mb-0" style="cursor:pointer">
                                    <i class="fas fa-upload mr-1"></i> Choose Photo
                                    <input type="file" wire:model.live="avatar" accept="image/*" style="display:none">
                                </label>
                                @if($user->avatar)
                                <button type="button" wire:click="removeAvatar" class="btn btn-outline-danger btn-sm ml-1"
                                        onclick="return confirm('Remove your profile photo?')">
                                    <i class="fas fa-trash mr-1"></i> Remove
                                </button>
                                @endif
                            </div>
                            @error('avatar') <div class="small text-danger">{{ $message }}</div> @enderror
                            <small class="text-muted">JPG, PNG or GIF · max 2 MB</small>
                            <div wire:loading wire:target="avatar" class="small text-info mt-1"><i class="fas fa-spinner fa-spin mr-1"></i> Uploading…</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-uppercase text-muted font-weight-bold mb-1" style="font-size:.72rem;letter-spacing:.08em">
                        Change Password
                    </h6>
                    <p class="text-muted small mb-3">Leave blank to keep your current password.</p>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small font-weight-bold text-muted mb-1">New Password</label>
                            <div class="input-group">
                                <input type="password"
                                       id="pwField"
                                       wire:model="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       placeholder="Min 8 characters"
                                       oninput="updateStrength(this.value)">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pwField',this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            {{-- Strength bar --}}
                            <div class="mt-2" id="strengthWrap" style="display:none">
                                <div class="progress" style="height:5px;border-radius:3px">
                                    <div id="strengthBar" class="progress-bar" style="width:0;transition:.3s"></div>
                                </div>
                                <small id="strengthLabel" class="text-muted"></small>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small font-weight-bold text-muted mb-1">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password"
                                       id="pwConfirm"
                                       wire:model="password_confirmation"
                                       class="form-control"
                                       placeholder="Repeat password">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pwConfirm',this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="button" wire:click="updateProfile"
                                class="btn btn-primary px-4 font-weight-bold shadow-sm"
                                style="background:{{ $roleColor['bg'] }};border-color:{{ $roleColor['bg'] }}">
                            <span wire:loading.remove wire:target="updateProfile"><i class="fas fa-save mr-2"></i>Save Changes</span>
                            <span wire:loading wire:target="updateProfile"><i class="fas fa-spinner fa-spin mr-2"></i>Saving…</span>
                        </button>
                    </div>
                </div>

                {{-- ── Security Tab ───────────────────────────── --}}
                @else
                <div class="card-body px-4 py-4">

                    <h6 class="text-uppercase text-muted font-weight-bold mb-3" style="font-size:.72rem;letter-spacing:.08em">
                        Recent Login Activity
                    </h6>

                    @if($loginLogs->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th><i class="fas fa-clock mr-1 text-muted"></i>Date &amp; Time</th>
                                    <th><i class="fas fa-network-wired mr-1 text-muted"></i>IP Address</th>
                                    <th><i class="fas fa-desktop mr-1 text-muted"></i>Device / Browser</th>
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
                                        <div class="font-weight-semibold">{{ $log->login_at->format('d M Y') }}</div>
                                        <small class="text-muted">{{ $log->login_at->format('H:i') }} · {{ $log->login_at->diffForHumans() }}</small>
                                    </td>
                                    <td class="align-middle">
                                        <code class="small">{{ $log->ip_address ?? '—' }}</code>
                                    </td>
                                    <td class="align-middle">
                                        <i class="{{ $deviceIcon }} mr-1 text-muted"></i>
                                        {{ $browser }} / {{ $device }}
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="badge badge-success">Successful</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-history fa-2x mb-2 d-block"></i>No login history found.
                        </div>
                    @endif

                    <div class="alert alert-info border-0 mt-3 small" style="border-radius:8px">
                        <i class="fas fa-info-circle mr-2"></i>
                        If you notice any unrecognised logins, change your password immediately and contact your system administrator.
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- Right: Sidebar ───────────────────────────── --}}
        <div class="col-lg-4">

            {{-- Stats Card --}}
            <div class="card border-0 shadow-sm mb-3" style="border-radius:10px;overflow:hidden">
                <div class="card-header font-weight-bold text-uppercase small text-muted border-bottom bg-white py-2 px-3"
                     style="letter-spacing:.06em">
                    Account Overview
                </div>
                <div class="card-body px-3 py-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-calendar-plus mr-2 text-primary"></i>Member Since</span>
                        <span class="small font-weight-bold">{{ $user->created_at->format('d M Y') }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-clock mr-2 text-info"></i>Account Age</span>
                        <span class="small font-weight-bold">{{ $accountAge }} days</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-sign-in-alt mr-2 text-success"></i>Total Logins</span>
                        <span class="small font-weight-bold">{{ $totalLogins }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-history mr-2 text-warning"></i>Last Login</span>
                        <span class="small font-weight-bold">
                            @if($lastLogin)
                                {{ $lastLogin->login_at->format('d M, H:i') }}
                            @else
                                First session
                            @endif
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted"><i class="fas fa-circle mr-2 {{ $user->is_active ? 'text-success' : 'text-danger' }}"></i>Status</span>
                        <span class="badge badge-{{ $user->is_active ? 'success' : 'danger' }} px-2">
                            {{ $user->is_active ? 'Active' : 'Suspended' }}
                        </span>
                    </div>
                </div>
                {{-- Mini stat bar --}}
                <div class="card-footer bg-white border-0 pt-0 px-3 pb-3">
                    <div class="row text-center">
                        <div class="col-4 border-right">
                            <div class="font-weight-bold text-primary" style="font-size:1.1rem">{{ $totalLogins }}</div>
                            <div class="text-muted" style="font-size:.7rem">Logins</div>
                        </div>
                        <div class="col-4 border-right">
                            <div class="font-weight-bold text-success" style="font-size:1.1rem">{{ $accountAge }}</div>
                            <div class="text-muted" style="font-size:.7rem">Days</div>
                        </div>
                        <div class="col-4">
                            <div class="font-weight-bold" style="font-size:1.1rem;color:{{ $roleColor['bg'] }}">
                                {{ $roles->count() }}
                            </div>
                            <div class="text-muted" style="font-size:.7rem">{{ Str::plural('Role', $roles->count()) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Staff Info Card --}}
            <div class="card border-0 shadow-sm mb-3" style="border-radius:10px;overflow:hidden">
                <div class="card-header font-weight-bold text-uppercase small text-muted border-bottom bg-white py-2 px-3"
                     style="letter-spacing:.06em">
                    <i class="fas fa-id-card mr-1"></i> Staff Details
                </div>
                <div class="card-body px-3 py-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-barcode mr-2 text-secondary"></i>Staff ID</span>
                        <span class="small font-weight-bold font-monospace">{{ $staff_id ?: '—' }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-building mr-2 text-secondary"></i>Department</span>
                        <span class="small font-weight-bold">{{ $department ?: '—' }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-calendar-check mr-2 text-secondary"></i>Hire Date</span>
                        <span class="small font-weight-bold">
                            {{ $hire_date ? \Carbon\Carbon::parse($hire_date)->format('d M Y') : '—' }}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-briefcase mr-2 text-secondary"></i>Service</span>
                        <span class="small font-weight-bold">{{ $user->service_length }}</span>
                    </div>
                    @if($user->date_of_birth)
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted"><i class="fas fa-birthday-cake mr-2 text-secondary"></i>Age</span>
                        <span class="small font-weight-bold">{{ $user->age }} yrs</span>
                    </div>
                    @endif
                    @if($phone)
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted"><i class="fas fa-phone mr-2 text-secondary"></i>Phone</span>
                        <a href="tel:{{ $phone }}" class="small font-weight-bold text-dark">{{ $phone }}</a>
                    </div>
                    @endif
                </div>
                <div class="card-footer bg-light border-0 py-2 px-3">
                    <small class="text-muted"><i class="fas fa-lock mr-1"></i> Staff details are managed by admin.</small>
                </div>
            </div>

            {{-- Roles Card --}}
            <div class="card border-0 shadow-sm mb-3" style="border-radius:10px;overflow:hidden">
                <div class="card-header font-weight-bold text-uppercase small text-muted border-bottom bg-white py-2 px-3"
                     style="letter-spacing:.06em">
                    <i class="fas fa-id-badge mr-1"></i> Access Roles
                </div>
                <div class="card-body px-3 py-3">
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
                    <div class="d-flex align-items-center p-2 mb-2 rounded"
                         style="background:{{ $rColor }}18;border-left:3px solid {{ $rColor }}">
                        <i class="{{ $rIcon }} mr-2" style="color:{{ $rColor }};width:16px"></i>
                        <span class="font-weight-semibold small" style="color:{{ $rColor }}">{{ $role }}</span>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Quick Links --}}
            <div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
                <div class="card-header font-weight-bold text-uppercase small text-muted border-bottom bg-white py-2 px-3"
                     style="letter-spacing:.06em">
                    <i class="fas fa-bolt mr-1"></i> Quick Links
                </div>
                <div class="list-group list-group-flush">
                    @foreach($quickLinks as $link)
                        @if(\Illuminate\Support\Facades\Route::has($link['route']))
                        <a href="{{ route($link['route']) }}"
                           class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3 small">
                            <i class="{{ $link['icon'] }} mr-2 text-muted" style="width:16px"></i>
                            {{ $link['label'] }}
                            <i class="fas fa-chevron-right ml-auto text-muted" style="font-size:.65rem"></i>
                        </a>
                        @endif
                    @endforeach
                    <a href="{{ route('staff.messages') }}"
                       class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3 small">
                        <i class="far fa-envelope mr-2 text-muted" style="width:16px"></i>
                        Messages
                        <i class="fas fa-chevron-right ml-auto text-muted" style="font-size:.65rem"></i>
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
        { w: '25%', cls: 'bg-danger',  text: 'Weak' },
        { w: '50%', cls: 'bg-warning', text: 'Fair' },
        { w: '75%', cls: 'bg-info',    text: 'Good' },
        { w: '100%',cls: 'bg-success', text: 'Strong' },
    ];
    var c = configs[score - 1] || configs[0];
    bar.style.width = c.w;
    bar.className   = 'progress-bar ' + c.cls;
    lbl.textContent = c.text;
}
</script>

<style>
.profile-cover { margin:-1rem -1rem 0; }
.font-weight-semibold { font-weight: 600; }
.nav-tabs .nav-link.active { border-bottom: 2px solid #fff; background: #fff; font-weight: 600; }
.list-group-item-action:hover { background:#f8f9fa; }
</style>
</div>
