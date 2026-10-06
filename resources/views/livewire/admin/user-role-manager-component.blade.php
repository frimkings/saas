<div class="clinic-ui ui-page" data-livewire-root>
<div class="w-full py-6">
    {{-- Header Section --}}
    <div class="mb-6">
        <div class="flex justify-between items-center mb-4">
            <div>
                @if($fromOptical)<a href="{{ route('optical.dashboard') }}" class="text-sm inline-block mb-1">← Back to Optical</a>@endif
                <h2 class="mb-1">User Management</h2>
                <p class="text-slate-500 mb-0">Manage staff members, roles, and permissions</p>
            </div>
            <div class="flex">
                <button wire:click="export" class="btn ui-button ui-button-secondary mr-2">
                    <i class="fas fa-download mr-1"></i> Export CSV
                </button>
                <button wire:click="openImport" class="btn ui-button ui-button-secondary mr-2">
                    <i class="fas fa-file-csv mr-1"></i> Import CSV
                </button>
                <button wire:click="create" class="btn ui-button ui-button-primary">
                    <i class="fas fa-plus mr-1"></i> Add Staff Member
                </button>
            </div>
        </div>

        {{-- Statistics Cards --}}
        <div class="flex flex-wrap -mx-2 mb-6">
            <div class="w-full md:w-3/12 px-2 mb-4">
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h6 class="text-slate-500 uppercase mb-2">Total Users</h6>
                        <h3 class="mb-0">{{ $stats['total'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="w-full md:w-3/12 px-2 mb-4">
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h6 class="text-slate-500 uppercase mb-2">Active Users</h6>
                        <h3 class="mb-0 text-green-700">{{ $stats['active'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="w-full md:w-3/12 px-2 mb-4">
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h6 class="text-slate-500 uppercase mb-2">Inactive Users</h6>
                        <h3 class="mb-0 text-red-700">{{ $stats['inactive'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="w-full md:w-3/12 px-2 mb-4">
                <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h6 class="text-slate-500 uppercase mb-2">Verified</h6>
                        <h3 class="mb-0 text-teal-700">{{ $stats['verified'] }}</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Search and Filter Section --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm mb-6">
        <div class="card-body p-4">
            <div class="flex flex-wrap -mx-2 items-end">
                {{-- Search Bar --}}
                <div class="w-full md:w-4/12 px-2 mb-4 md:mb-0">
                    <label class="text-sm font-semibold text-slate-500 mb-1">Search</label>
                    <div class="flex items-stretch">
                        <div class="flex">
                            <span class="flex items-center border border-slate-300 px-2 text-sm text-slate-600 bg-white border-r-0">
                                <i class="fas fa-search text-slate-500"></i>
                            </span>
                        </div>
                        <input wire:model.live.debounce.400ms="search" 
                            type="text" 
                            class="form-control ui-input border-l-0" 
                            placeholder="Search by name or email...">
                        @if($search)
                            <div class="flex">
                                <button wire:click="$set('search', '')" class="btn ui-button ui-button-secondary" type="button">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Filter Toggle & Actions --}}
                <div class="w-full md:w-8/12 px-2 flex justify-end">
                    <button wire:click="$toggle('showFilters')" 
                        class="btn ui-button {{ $showFilters ? 'ui-button-primary' : 'ui-button-secondary' }} mr-2">
                        <i class="fas fa-filter mr-1"></i> Filters
                        @if($filterRole || $filterStatus !== '' || $filterEmailVerified !== '' || $filterDateFrom || $filterDateTo)
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-slate-50 text-slate-600 ml-1">
                                {{ collect([$filterRole, $filterStatus, $filterEmailVerified, $filterDateFrom, $filterDateTo])->filter()->count() }}
                            </span>
                        @endif
                    </button>

                    @if($filterRole || $filterStatus !== '' || $filterEmailVerified !== '' || $filterDateFrom || $filterDateTo || $search)
                        <button wire:click="clearFilters" class="btn ui-button ui-button-danger mr-2">
                            <i class="fas fa-times mr-1"></i> Clear All
                        </button>
                    @endif

                    <select wire:model.live="perPage" class="ui-input" style="width: auto;">
                        <option value="10">10 per page</option>
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                </div>
            </div>

            {{-- Advanced Filters Panel --}}
            @if($showFilters)
                <hr>
                <div class="flex flex-wrap -mx-2">
                    {{-- Filter by Role --}}
                    <div class="w-full md:w-auto md:flex-1 px-2 mb-4">
                        <label class="text-sm font-semibold text-slate-500 mb-1">Role</label>
                        <select wire:model.live="filterRole" class="ui-input">
                            <option value="">All Roles</option>
                            @foreach($availableRoles as $role)
                                <option value="{{ $role->name }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Filter by Status --}}
                    <div class="w-full md:w-auto md:flex-1 px-2 mb-4">
                        <label class="text-sm font-semibold text-slate-500 mb-1">Status</label>
                        <select wire:model.live="filterStatus" class="ui-input">
                            <option value="">All Status</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>

                    {{-- Filter by Email Verification --}}
                    <div class="w-full md:w-auto md:flex-1 px-2 mb-4">
                        <label class="text-sm font-semibold text-slate-500 mb-1">Email Verified</label>
                        <select wire:model.live="filterEmailVerified" class="ui-input">
                            <option value="">All</option>
                            <option value="1">Verified</option>
                            <option value="0">Not Verified</option>
                        </select>
                    </div>

                    {{-- Filter by date added --}}
                    <div class="w-full md:w-auto md:flex-1 px-2 mb-4">
                        <label class="text-sm font-semibold text-slate-500 mb-1 block">Added</label>
                        <x-date-range from="filterDateFrom" to="filterDateTo" presets="activity" clearable />
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Users Table --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="ui-table-wrap">
            <table class="table ui-table mb-0">
                <thead class="">
                    <tr>
                        <th wire:click="sortBy('id')" style="cursor: pointer;">
                            ID
                            @if($sortField === 'id')
                                <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ml-1"></i>
                            @endif
                        </th>
                        <th wire:click="sortBy('name')" style="cursor: pointer;">
                            User Details
                            @if($sortField === 'name')
                                <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ml-1"></i>
                            @endif
                        </th>
                        <th>Roles</th>
                        <th class="text-center" wire:click="sortBy('is_active')" style="cursor: pointer;">
                            Status
                            @if($sortField === 'is_active')
                                <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ml-1"></i>
                            @endif
                        </th>
                        <th class="text-center" wire:click="sortBy('email_verified_at')" style="cursor: pointer;">
                            Verified
                            @if($sortField === 'email_verified_at')
                                <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ml-1"></i>
                            @endif
                        </th>
                        <th>Last Login</th>
                        <th wire:click="sortBy('created_at')" style="cursor: pointer;">
                            Created
                            @if($sortField === 'created_at')
                                <i class="fas fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }} ml-1"></i>
                            @endif
                        </th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td>
                                <small class="text-slate-500">#{{ $user->id }}</small>
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <div class="avatar-circle bg-teal-700 text-white mr-2" style="overflow:hidden">
                                        @if($user->avatar_url)
                                            <img src="{{ $user->avatar_url }}" alt="" style="width:100%;height:100%;object-fit:cover">
                                        @else
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-semibold">{{ $user->name }}</div>
                                        <small class="text-slate-500">{{ $user->email }}</small>
                                        @if($user->clinic_staff_id)
                                            <br><small class="text-slate-500"><i class="fas fa-barcode mr-1"></i>{{ $user->clinic_staff_id }}</small>
                                        @endif
                                        @if($user->department)
                                            <br><small class="text-slate-500"><i class="fas fa-building mr-1"></i>{{ $user->department }}</small>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                @forelse($user->roles as $role)
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-teal-100 text-teal-800 mr-1">{{ $role->name }}</span>
                                @empty
                                    <small class="text-slate-500 italic">No roles assigned</small>
                                @endforelse
                            </td>
                            <td class="text-center">
                                <div class="flex items-center gap-2">
                                    <input type="checkbox" 
                                        class="rounded border-slate-300 text-teal-700" 
                                        id="status-{{ $user->id }}"
                                        wire:click="toggleStatus({{ $user->id }})"
                                        title="Active in this clinic only"
                                        {{ $user->membership_status === 'active' ? 'checked' : '' }}>
                                    <label class="" for="status-{{ $user->id }}"></label>
                                </div>
                                <small class="block {{ $user->membership_status === 'active' ? 'text-green-700' : 'text-slate-500' }}">
                                    {{ $user->membership_status === 'active' ? 'Active' : 'Inactive' }}
                                </small>
                            </td>
                            <td class="text-center">
                                @if($user->email_verified_at)
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i> Verified
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">
                                        <i class="fas fa-exclamation-circle mr-1"></i> Pending
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($user->latestLogin)
                                    <div class="text-sm">{{ $user->latestLogin->login_at->diffForHumans() }}</div>
                                    <small class="text-slate-500">{{ $user->latestLogin->ip_address }}</small>
                                @else
                                    <small class="text-slate-500 italic">Never logged in</small>
                                @endif
                            </td>
                            <td>
                                <div class="text-sm">{{ $user->created_at->format('M d, Y') }}</div>
                                <small class="text-slate-500">{{ $user->created_at->format('h:i A') }}</small>
                            </td>
                            <td class="text-right">
                                <button wire:click="edit({{ $user->id }})"
                                    class="btn ui-button ui-button-sm ui-button-secondary"
                                    title="Edit user">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <button wire:click="openResetPassword({{ $user->id }})"
                                    class="btn ui-button ui-button-sm ui-button-secondary"
                                    title="Reset password">
                                    <i class="fas fa-key"></i>
                                </button>

                                <div class="inline-flex flex-wrap gap-1" role="group" x-data="{ confirmDelete: false }">
                                    <button x-show="!confirmDelete"
                                        @click="confirmDelete = true"
                                        class="btn ui-button ui-button-sm ui-button-danger"
                                        title="Remove from this clinic (their account and other clinics are kept)">
                                        <i class="fas fa-user-minus"></i>
                                    </button>
                                    <div x-show="confirmDelete" x-cloak class="inline-flex flex-wrap gap-1" role="group">
                                        <button wire:click="delete({{ $user->id }})"
                                            class="btn ui-button ui-button-sm ui-button-danger">
                                            Remove
                                        </button>
                                        <button @click="confirmDelete = false"
                                            class="btn ui-button ui-button-sm ui-button-secondary">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-12">
                                <i class="fas fa-users fa-3x text-slate-500 mb-4"></i>
                                <h5 class="text-slate-500">No users found</h5>
                                <p class="text-slate-500">Try adjusting your search or filter criteria</p>
                                @if($search || $filterRole || $filterStatus !== '' || $filterEmailVerified !== '')
                                    <button wire:click="clearFilters" class="btn ui-button ui-button-primary">
                                        Clear All Filters
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($users->hasPages())
            <div class="border-t border-slate-200 px-4 py-2 bg-slate-50">
                {{ $users->links() }}
            </div>
        @endif
    </div>

    {{-- Create/Edit Modal --}}
    @if($isOpen)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="mx-auto my-8 w-full max-w-3xl" role="document">
            <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl" style="max-height: 90vh;">
                {{-- Modal Header --}}
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <h5 class="text-base font-semibold">
                        {{ $isEdit ? 'Update Staff Member' : 'Register New Staff Member' }}
                    </h5>
                    <button type="button" class="text-xl leading-none text-slate-500 hover:text-slate-800" wire:click="closeModal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                {{-- Modal Body --}}
                <form wire:submit="store">
                    <div class="p-4" style="max-height: 60vh; overflow-y: auto;">
                        {{-- Name Field --}}
                        <div class="mb-4">
                            <label class="font-semibold">
                                Full Name <span class="text-red-700">*</span>
                            </label>
                            <input type="text" 
                                wire:model.live.debounce.400ms="name"
                                class="form-control ui-input @error('name') is-invalid @enderror"
                                placeholder="Enter full name">
                            @error('name') 
                                <div class="ui-error">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Email Field --}}
                        <div class="mb-4">
                            <label class="font-semibold">
                                Email Address <span class="text-red-700">*</span>
                            </label>
                            <input type="email" 
                                wire:model.live.debounce.400ms="email"
                                class="form-control ui-input @error('email') is-invalid @enderror"
                                placeholder="email@example.com">
                            @error('email') 
                                <div class="ui-error">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Password Field --}}
                        <div class="mb-4">
                            <label class="font-semibold">
                                Password {{ $isEdit ? '' : '*' }}
                            </label>
                            <div class="flex items-stretch">
                                <input type="password"
                                    id="staff-password"
                                    wire:model.live.debounce.400ms="password"
                                    class="form-control ui-input @error('password') is-invalid @enderror"
                                    placeholder="{{ $isEdit ? 'Leave blank to keep current password' : 'Enter secure password' }}">
                                <div class="flex">
                                    <button type="button"
                                        class="btn ui-button ui-button-secondary password-toggle"
                                        data-password-toggle="staff-password"
                                        aria-label="Show password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('password')
                                    <div class="ui-error">{{ $message }}</div>
                                @enderror
                            </div>
                            <small class="mt-1 block text-xs text-slate-500">
                                {{ $isEdit ? 'Leave blank to keep the current password. ' : '' }}Password must be at least 6 characters.
                            </small>
                        </div>

                        {{-- Confirm Password Field --}}
                        <div class="mb-4">
                            <label class="font-semibold">
                                Confirm Password {{ $isEdit ? '' : '*' }}
                            </label>
                            <div class="flex items-stretch">
                                <input type="password"
                                    id="staff-password-confirmation"
                                    wire:model.live.debounce.400ms="password_confirmation"
                                    class="form-control ui-input @error('password_confirmation') is-invalid @enderror"
                                    placeholder="{{ $isEdit ? 'Repeat new password when changing it' : 'Repeat secure password' }}">
                                <div class="flex">
                                    <button type="button"
                                        class="btn ui-button ui-button-secondary password-toggle"
                                        data-password-toggle="staff-password-confirmation"
                                        aria-label="Show confirm password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('password_confirmation')
                                    <div class="ui-error">{{ $message }}</div>
                                @enderror
                            </div>
                            @error('password') 
                                @if($message === 'The password confirmation does not match.')
                                    <small class="text-red-700">{{ $message }}</small>
                                @endif
                            @enderror
                        </div>

                        {{-- Roles Field --}}
                        <div class="mb-4">
                            <label class="font-semibold">
                                Role assignment <span class="text-red-700">*</span>
                            </label>
                            <div class="flex flex-wrap mb-2">
                                <div class="flex items-center gap-2 mr-6">
                                    <input type="radio" class="rounded border-slate-300 text-teal-700" id="roles-shared" wire:model.live="roleAssignmentMode" value="shared">
                                    <label class="" for="roles-shared">Same roles for all branches</label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="radio" class="rounded border-slate-300 text-teal-700" id="roles-custom" wire:model.live="roleAssignmentMode" value="custom">
                                    <label class="" for="roles-custom">Different roles per branch</label>
                                </div>
                            </div>
                            @error('roleAssignmentMode')<small class="text-red-700 block">{{ $message }}</small>@enderror
                            {{-- What each optical role opens (App\Support\OpticalAccess). --}}
                            @feature('optical')
                            <details class="text-sm text-slate-500 mb-2">
                                <summary class="font-semibold" style="cursor:pointer">Optical roles: what each can open</summary>
                                <ul class="mb-0 pl-4 mt-1">
                                    <li><b>Manager</b>: every optical screen, including purchasing, expenses, profit &amp; loss, settings and adding staff.</li>
                                    @foreach(\App\Support\OpticalAccess::ROLES as $roleName => $role)<li><b>{{ $roleName }}</b>: {{ $role['about'] }}</li>@endforeach
                                </ul>
                            </details>
                            @endfeature

                            @if($roleAssignmentMode === 'shared')
                            <input type="text"
                                wire:model.live.debounce.300ms="roleSearch"
                                placeholder="Search roles..."
                                class="form-control ui-input ui-input-sm mb-2">

                            <div class="border border-slate-200 rounded-md p-2 bg-slate-50" style="max-height: 150px; overflow-y: auto;">
                                <div class="flex flex-wrap -mx-2">
                                    @forelse($allRoles as $role)
                                        <div class="w-full md:w-6/12 px-2 mb-2">
                                            <div class="flex items-center gap-2">
                                                <input type="checkbox"
                                                    class="rounded border-slate-300 text-teal-700"
                                                    id="role-{{ $role->id }}"
                                                    wire:model.live="selectedRoles"
                                                    value="{{ $role->name }}">
                                                <label class="" for="role-{{ $role->id }}">
                                                    {{ $role->name }}
                                                </label>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="w-full px-2">
                                            <p class="text-slate-500 text-center italic mb-0 text-sm">No roles match your search</p>
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                            @error('selectedRoles')
                                <small class="text-red-700">{{ $message }}</small>
                            @enderror
                            @else
                                @if(empty($selectedBranchIds))
                                    <div class="rounded-lg border px-3 py-2 text-sm bg-white text-slate-700 border-slate-200 mb-0">Select the allowed branches below, then assign roles to each branch.</div>
                                @endif
                                @foreach($availableBranches->whereIn('id', array_map('intval', $selectedBranchIds)) as $branch)
                                    <div class="border border-slate-200 rounded-md p-2 mb-2 bg-slate-50">
                                        <div class="font-semibold text-sm mb-2">{{ $branch->name }} <span class="text-slate-500">({{ $branch->code }})</span></div>
                                        <div class="flex flex-wrap -mx-2">
                                            @foreach($availableRoles as $role)
                                                <div class="w-full md:w-6/12 px-2 mb-1">
                                                    <div class="flex items-center gap-2">
                                                        <input type="checkbox" class="rounded border-slate-300 text-teal-700"
                                                            id="branch-{{ $branch->id }}-role-{{ $role->id }}"
                                                            wire:model.live="branchRoles.{{ $branch->id }}"
                                                            value="{{ $role->name }}">
                                                        <label class="" for="branch-{{ $branch->id }}-role-{{ $role->id }}">{{ $role->name }}</label>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                        @error('branchRoles.'.$branch->id)<small class="text-red-700">{{ $message }}</small>@enderror
                                    </div>
                                @endforeach
                            @endif
                        </div>

                        <hr>
                        <p class="uppercase text-slate-500 font-semibold mb-4" style="font-size:.72rem;letter-spacing:.08em">
                            <i class="fas fa-code-branch mr-1"></i> Clinic &amp; Branch Access
                        </p>

                        <div class="flex flex-wrap -mx-2">
                            <div class="w-full md:w-6/12 px-2">
                                <div class="mb-4">
                                    <label class="font-semibold text-sm">Membership Status <span class="text-red-700">*</span></label>
                                    <select wire:model="membershipStatus" class="ui-input @error('membershipStatus') is-invalid @enderror">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    @error('membershipStatus')<div class="ui-error">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="w-full md:w-6/12 px-2">
                                <div class="mb-4">
                                    <label class="font-semibold text-sm">Staff ID</label>
                                    <input type="text" wire:model="staff_id" maxlength="30" class="form-control ui-input @error('staff_id') is-invalid @enderror" placeholder="e.g. EMP-0042">
                                    <small class="mt-1 block text-xs text-slate-500">This clinic's own number for the person. Must be unique within this clinic.</small>
                                    @error('staff_id')<div class="ui-error">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="w-full md:w-7/12 px-2">
                                <label class="font-semibold text-sm">Allowed Branches <span class="text-red-700">*</span></label>
                                <div class="border border-slate-200 rounded-md p-2 bg-slate-50" style="max-height:150px;overflow-y:auto">
                                    @forelse($availableBranches as $branch)
                                        <div class="flex items-center gap-2 mb-1">
                                            <input type="checkbox" class="rounded border-slate-300 text-teal-700" id="staff-branch-{{ $branch->id }}" wire:model.live="selectedBranchIds" value="{{ $branch->id }}">
                                            <label class="" for="staff-branch-{{ $branch->id }}">{{ $branch->name }} <small class="text-slate-500">({{ $branch->code }})</small></label>
                                        </div>
                                    @empty
                                        <span class="text-red-700 text-sm">Create an active branch before adding staff.</span>
                                    @endforelse
                                </div>
                                @error('selectedBranchIds')<small class="text-red-700">{{ $message }}</small>@enderror
                                @error('selectedBranchIds.*')<small class="text-red-700 block">{{ $message }}</small>@enderror
                            </div>
                            <div class="w-full md:w-5/12 px-2">
                                <div class="mb-4">
                                    <label class="font-semibold text-sm">Default Branch <span class="text-red-700">*</span></label>
                                    <select wire:model="defaultBranchId" class="ui-input @error('defaultBranchId') is-invalid @enderror">
                                        <option value="">Select default</option>
                                        @foreach($availableBranches as $branch)
                                            @if(in_array($branch->id, array_map('intval', $selectedBranchIds)))
                                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error('defaultBranchId')<div class="ui-error">{{ $message }}</div>@enderror
                                    <small class="mt-1 block text-xs text-slate-500">The branch opened immediately after login.</small>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <p class="uppercase text-slate-500 font-semibold mb-4" style="font-size:.72rem;letter-spacing:.08em">
                            <i class="fas fa-id-card mr-1"></i> Staff Profile
                        </p>

                        <div class="flex flex-wrap -mx-2">
                            {{-- Phone --}}
                            <div class="w-full md:w-6/12 px-2">
                                <div class="mb-4">
                                    <label class="font-semibold text-sm">Phone</label>
                                    <div class="flex items-stretch">
                                        <div class="flex">
                                            <span class="flex items-center border border-slate-300 bg-slate-50 px-2 text-sm text-slate-600"><i class="fas fa-phone text-slate-500"></i></span>
                                        </div>
                                        <input type="text"
                                            wire:model="phone"
                                            class="form-control ui-input @error('phone') is-invalid @enderror"
                                            placeholder="+63 912 345 6789">
                                        @error('phone')<div class="ui-error">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                            {{-- Gender --}}
                            <div class="w-full md:w-4/12 px-2">
                                <div class="mb-4">
                                    <label class="font-semibold text-sm">Gender</label>
                                    <select wire:model="gender"
                                            class="ui-input @error('gender') is-invalid @enderror">
                                        <option value="">— Select —</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    @error('gender')<div class="ui-error">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            {{-- Date of Birth --}}
                            <div class="w-full md:w-4/12 px-2">
                                <div class="mb-4">
                                    <label class="font-semibold text-sm">Date of Birth</label>
                                    <input type="date"
                                        wire:model="date_of_birth"
                                        class="form-control ui-input @error('date_of_birth') is-invalid @enderror">
                                    @error('date_of_birth')<div class="ui-error">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            {{-- Hire Date --}}
                            <div class="w-full md:w-4/12 px-2">
                                <div class="mb-4">
                                    <label class="font-semibold text-sm">Hire Date</label>
                                    <input type="date"
                                        wire:model="hire_date"
                                        class="form-control ui-input @error('hire_date') is-invalid @enderror">
                                    @error('hire_date')<div class="ui-error">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            {{-- Department --}}
                            <div class="w-full md:w-full px-2">
                                <div class="mb-0">
                                    <label class="font-semibold text-sm">Department</label>
                                    <input type="text"
                                        wire:model="department"
                                        class="form-control ui-input @error('department') is-invalid @enderror"
                                        placeholder="e.g. Ophthalmology, Administration">
                                    @error('department')<div class="ui-error">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Modal Footer --}}
                    <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                        <button type="button" class="btn ui-button ui-button-secondary" wire:click="closeModal">
                            Cancel
                        </button>
                        <button type="submit" class="btn ui-button ui-button-primary">
                            {{ $isEdit ? 'Save Changes' : 'Create Staff Member' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- Admin Reset Password Modal --}}
    @if($isResetOpen)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="mx-auto my-8 w-full max-w-sm" role="document">
            <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3" style="background: linear-gradient(135deg,#0f3460,#0d7377); color:#fff;">
                    <h5 class="text-base font-semibold">
                        <i class="fas fa-key mr-2"></i> Reset Password
                    </h5>
                    <button type="button" class="text-xl leading-none text-slate-500 hover:text-slate-800" wire:click="closeResetModal" style="color:#fff; opacity:1;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <form wire:submit="doResetPassword">
                    <div class="p-4">
                        <div class="text-center mb-4">
                            <div class="inline-flex items-center justify-center rounded-full mb-2"
                                style="width:48px;height:48px;background:linear-gradient(135deg,#0f3460,#0d7377);">
                                <i class="fas fa-user text-white"></i>
                            </div>
                            <div class="font-semibold">{{ $resetUserName }}</div>
                            <small class="text-slate-500">Setting a new password for this account</small>
                        </div>

                        <div class="mb-4">
                            <label class="font-semibold text-sm">New Password <span class="text-red-700">*</span></label>
                            <div class="flex items-stretch">
                                <input type="password"
                                    id="reset-password"
                                    wire:model.live.debounce.400ms="newPassword"
                                    class="form-control ui-input @error('newPassword') is-invalid @enderror"
                                    placeholder="Min. 6 characters">
                                <div class="flex">
                                    <button type="button"
                                        class="btn ui-button ui-button-secondary password-toggle"
                                        data-password-toggle="reset-password"
                                        aria-label="Show new password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('newPassword')
                                    <div class="ui-error">{{ $message }}</div>
                                @enderror
                            </div>
                            <small class="mt-1 block text-xs text-slate-500">Password must be at least 6 characters.</small>
                        </div>

                        <div class="mb-0">
                            <label class="font-semibold text-sm">Confirm Password <span class="text-red-700">*</span></label>
                            <div class="flex items-stretch">
                                <input type="password"
                                    id="reset-password-confirmation"
                                    wire:model.live.debounce.400ms="newPasswordConfirmation"
                                    class="form-control ui-input @error('newPasswordConfirmation') is-invalid @enderror"
                                    placeholder="Repeat new password">
                                <div class="flex">
                                    <button type="button"
                                        class="btn ui-button ui-button-secondary password-toggle"
                                        data-password-toggle="reset-password-confirmation"
                                        aria-label="Show confirm password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('newPasswordConfirmation')
                                    <div class="ui-error">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                        <button type="button" class="btn ui-button ui-button-secondary ui-button-sm" wire:click="closeResetModal">Cancel</button>
                        <button type="submit" class="btn ui-button ui-button-sm text-white ui-button-secondary"
                            style="background:linear-gradient(135deg,#0f3460,#0d7377);">
                            <i class="fas fa-check mr-1"></i> Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- CSV Import Modal --}}
    @if($isImportOpen)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="mx-auto my-8 w-full max-w-3xl" role="document">
            <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-green-600 text-white">
                    <h5 class="text-base font-semibold">
                        <i class="fas fa-file-csv mr-2"></i> Bulk Import Staff
                    </h5>
                    <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" wire:click="closeImportModal">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="p-4">

                    {{-- Instructions --}}
                    <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 border-0 mb-6">
                        <i class="fas fa-info-circle mr-1"></i>
                        Upload a <strong>.csv</strong> file with one staff member per row.
                        Required columns: <code>name</code>, <code>email</code>, <code>password</code>, <code>role</code>.
                        Optional: <code>phone</code>, <code>staff_id</code>, <code>gender</code>, <code>date_of_birth</code>, <code>department</code>, <code>hire_date</code>.
                        <br>
                        <button wire:click="downloadTemplate" class="btn ui-button ui-button-link ui-button-sm p-0 mt-1">
                            <i class="fas fa-download mr-1"></i> Download template CSV
                        </button>
                    </div>

                    {{-- File input --}}
                    @if(empty($importResults))
                        <div class="mb-4">
                            <label class="font-semibold">Select CSV File <span class="text-red-700">*</span></label>
                            <input type="file"
                                   wire:model.live="importFile"
                                   accept=".csv,.txt"
                                   class="form-control-file @error('importFile') is-invalid @enderror">
                            @error('importFile')
                                <div class="text-red-700 text-sm mt-1">{{ $message }}</div>
                            @enderror
                            <div wire:loading wire:target="importFile" class="text-slate-500 text-sm mt-1">
                                <i class="fas fa-spinner fa-spin mr-1"></i> Uploading…
                            </div>
                        </div>
                    @endif

                    {{-- Results --}}
                    @if(!empty($importResults))
                        <div class="flex flex-wrap -mx-2 mb-4">
                            <div class="w-full md:w-6/12 px-2">
                                <div class="border border-slate-200 text-center p-4" style="background:#eaf7ee;">
                                    <div class="text-lg font-semibold text-green-700 mb-0">{{ $importResults['created'] }}</div>
                                    <small class="text-slate-500">Imported</small>
                                </div>
                            </div>
                            <div class="w-full md:w-6/12 px-2">
                                <div class="border border-slate-200 text-center p-4" style="background:#fdecee;">
                                    <div class="text-lg font-semibold text-red-700 mb-0">{{ $importResults['skipped'] }}</div>
                                    <small class="text-slate-500">Skipped / Errors</small>
                                </div>
                            </div>
                        </div>

                        @if(!empty($importResults['errors']))
                            <div class="border border-slate-200 rounded-md p-4 bg-slate-50" style="max-height:200px;overflow-y:auto;">
                                @foreach($importResults['errors'] as $err)
                                    <div class="text-sm text-red-700 mb-1">
                                        <i class="fas fa-exclamation-circle mr-1"></i>{{ $err }}
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <button wire:click="$set('importResults', [])" class="btn ui-button ui-button-sm ui-button-secondary mt-4">
                            <i class="fas fa-redo mr-1"></i> Import Another File
                        </button>
                    @endif

                </div>

                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <button type="button" class="btn ui-button ui-button-secondary" wire:click="closeImportModal">
                        {{ empty($importResults) ? 'Cancel' : 'Close' }}
                    </button>
                    @if(empty($importResults))
                        <button type="button"
                                class="btn ui-button ui-button-primary"
                                wire:click="importCsv"
                                wire:loading.attr="disabled"
                                wire:target="importCsv"
                                @if(!$importFile) disabled @endif>
                            <span wire:loading.remove wire:target="importCsv">
                                <i class="fas fa-upload mr-1"></i> Import
                            </span>
                            <span wire:loading wire:target="importCsv">
                                <i class="fas fa-spinner fa-spin mr-1"></i> Importing…
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

</div>

{{-- Custom Styles --}}
<style>
    [x-cloak] { display: none !important; }
    
    .avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        flex-shrink: 0;
    }
    
    .table td {
        vertical-align: middle;
    }
    
    .custom-switch .custom-control-label::before {
        cursor: pointer;
    }
    
    .custom-switch .custom-control-input:checked ~ .custom-control-label::before {
        background-color: #28a745;
        border-color: #28a745;
    }
</style>

<script>
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-password-toggle]');
        if (!button) {
            return;
        }

        const input = document.getElementById(button.dataset.passwordToggle);
        const icon = button.querySelector('i');

        if (!input || !icon) {
            return;
        }

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        icon.classList.toggle('fa-eye', !isPassword);
        icon.classList.toggle('fa-eye-slash', isPassword);
        button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
</script>
</div>
