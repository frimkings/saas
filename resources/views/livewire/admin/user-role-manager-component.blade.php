<div data-livewire-root>
<div class="container-fluid py-4">
    {{-- Header Section --}}
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">User Management</h2>
                <p class="text-muted mb-0">Manage staff members, roles, and permissions</p>
            </div>
            <div class="d-flex">
                <button wire:click="export" class="btn btn-outline-secondary mr-2">
                    <i class="fas fa-download mr-1"></i> Export CSV
                </button>
                <button wire:click="openImport" class="btn btn-outline-success mr-2">
                    <i class="fas fa-file-csv mr-1"></i> Import CSV
                </button>
                <button wire:click="create" class="btn btn-primary">
                    <i class="fas fa-plus mr-1"></i> Add Staff Member
                </button>
            </div>
        </div>

        {{-- Statistics Cards --}}
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Total Users</h6>
                        <h3 class="mb-0">{{ $stats['total'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Active Users</h6>
                        <h3 class="mb-0 text-success">{{ $stats['active'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Inactive Users</h6>
                        <h3 class="mb-0 text-danger">{{ $stats['inactive'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Verified</h6>
                        <h3 class="mb-0 text-primary">{{ $stats['verified'] }}</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Search and Filter Section --}}
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-end">
                {{-- Search Bar --}}
                <div class="col-md-4 mb-3 mb-md-0">
                    <label class="small font-weight-bold text-muted mb-1">Search</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-right-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                        </div>
                        <input wire:model.live.debounce.400ms="search" 
                            type="text" 
                            class="form-control border-left-0" 
                            placeholder="Search by name or email...">
                        @if($search)
                            <div class="input-group-append">
                                <button wire:click="$set('search', '')" class="btn btn-outline-secondary" type="button">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Filter Toggle & Actions --}}
                <div class="col-md-8 d-flex justify-content-end">
                    <button wire:click="$toggle('showFilters')" 
                        class="btn {{ $showFilters ? 'btn-primary' : 'btn-outline-secondary' }} mr-2">
                        <i class="fas fa-filter mr-1"></i> Filters
                        @if($filterRole || $filterStatus !== '' || $filterEmailVerified !== '' || $filterDateFrom || $filterDateTo)
                            <span class="badge badge-light ml-1">
                                {{ collect([$filterRole, $filterStatus, $filterEmailVerified, $filterDateFrom, $filterDateTo])->filter()->count() }}
                            </span>
                        @endif
                    </button>

                    @if($filterRole || $filterStatus !== '' || $filterEmailVerified !== '' || $filterDateFrom || $filterDateTo || $search)
                        <button wire:click="clearFilters" class="btn btn-outline-danger mr-2">
                            <i class="fas fa-times mr-1"></i> Clear All
                        </button>
                    @endif

                    <select wire:model.live="perPage" class="custom-select" style="width: auto;">
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
                <div class="row">
                    {{-- Filter by Role --}}
                    <div class="col-md mb-3">
                        <label class="small font-weight-bold text-muted mb-1">Role</label>
                        <select wire:model.live="filterRole" class="custom-select">
                            <option value="">All Roles</option>
                            @foreach($availableRoles as $role)
                                <option value="{{ $role->name }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Filter by Status --}}
                    <div class="col-md mb-3">
                        <label class="small font-weight-bold text-muted mb-1">Status</label>
                        <select wire:model.live="filterStatus" class="custom-select">
                            <option value="">All Status</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>

                    {{-- Filter by Email Verification --}}
                    <div class="col-md mb-3">
                        <label class="small font-weight-bold text-muted mb-1">Email Verified</label>
                        <select wire:model.live="filterEmailVerified" class="custom-select">
                            <option value="">All</option>
                            <option value="1">Verified</option>
                            <option value="0">Not Verified</option>
                        </select>
                    </div>

                    {{-- Filter by date added --}}
                    <div class="col-md mb-3">
                        <label class="small font-weight-bold text-muted mb-1 d-block">Added</label>
                        <x-date-range from="filterDateFrom" to="filterDateTo" presets="activity" clearable />
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Users Table --}}
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
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
                                <small class="text-muted">#{{ $user->id }}</small>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle bg-primary text-white mr-2" style="overflow:hidden">
                                        @if($user->avatar_url)
                                            <img src="{{ $user->avatar_url }}" alt="" style="width:100%;height:100%;object-fit:cover">
                                        @else
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-weight-bold">{{ $user->name }}</div>
                                        <small class="text-muted">{{ $user->email }}</small>
                                        @if($user->clinic_staff_id)
                                            <br><small class="text-muted"><i class="fas fa-barcode mr-1"></i>{{ $user->clinic_staff_id }}</small>
                                        @endif
                                        @if($user->department)
                                            <br><small class="text-muted"><i class="fas fa-building mr-1"></i>{{ $user->department }}</small>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                @forelse($user->roles as $role)
                                    <span class="badge badge-primary mr-1">{{ $role->name }}</span>
                                @empty
                                    <small class="text-muted font-italic">No roles assigned</small>
                                @endforelse
                            </td>
                            <td class="text-center">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" 
                                        class="custom-control-input" 
                                        id="status-{{ $user->id }}"
                                        wire:click="toggleStatus({{ $user->id }})"
                                        title="Active in this clinic only"
                                        {{ $user->membership_status === 'active' ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="status-{{ $user->id }}"></label>
                                </div>
                                <small class="d-block {{ $user->membership_status === 'active' ? 'text-success' : 'text-muted' }}">
                                    {{ $user->membership_status === 'active' ? 'Active' : 'Inactive' }}
                                </small>
                            </td>
                            <td class="text-center">
                                @if($user->email_verified_at)
                                    <span class="badge badge-success">
                                        <i class="fas fa-check-circle mr-1"></i> Verified
                                    </span>
                                @else
                                    <span class="badge badge-warning">
                                        <i class="fas fa-exclamation-circle mr-1"></i> Pending
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($user->latestLogin)
                                    <div class="small">{{ $user->latestLogin->login_at->diffForHumans() }}</div>
                                    <small class="text-muted">{{ $user->latestLogin->ip_address }}</small>
                                @else
                                    <small class="text-muted font-italic">Never logged in</small>
                                @endif
                            </td>
                            <td>
                                <div class="small">{{ $user->created_at->format('M d, Y') }}</div>
                                <small class="text-muted">{{ $user->created_at->format('h:i A') }}</small>
                            </td>
                            <td class="text-right">
                                <button wire:click="edit({{ $user->id }})"
                                    class="btn btn-sm btn-outline-primary"
                                    title="Edit user">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <button wire:click="openResetPassword({{ $user->id }})"
                                    class="btn btn-sm btn-outline-warning"
                                    title="Reset password">
                                    <i class="fas fa-key"></i>
                                </button>

                                <div class="btn-group" role="group" x-data="{ confirmDelete: false }">
                                    <button x-show="!confirmDelete"
                                        @click="confirmDelete = true"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Remove from this clinic (their account and other clinics are kept)">
                                        <i class="fas fa-user-minus"></i>
                                    </button>
                                    <div x-show="confirmDelete" x-cloak class="btn-group" role="group">
                                        <button wire:click="delete({{ $user->id }})"
                                            class="btn btn-sm btn-danger">
                                            Remove
                                        </button>
                                        <button @click="confirmDelete = false"
                                            class="btn btn-sm btn-secondary">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No users found</h5>
                                <p class="text-muted">Try adjusting your search or filter criteria</p>
                                @if($search || $filterRole || $filterStatus !== '' || $filterEmailVerified !== '')
                                    <button wire:click="clearFilters" class="btn btn-primary">
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
            <div class="card-footer bg-light">
                {{ $users->links() }}
            </div>
        @endif
    </div>

    {{-- Create/Edit Modal --}}
    @if($isOpen)
    <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
            <div class="modal-content" style="max-height: 90vh;">
                {{-- Modal Header --}}
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ $isEdit ? 'Update Staff Member' : 'Register New Staff Member' }}
                    </h5>
                    <button type="button" class="close" wire:click="closeModal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                {{-- Modal Body --}}
                <form wire:submit="store">
                    <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                        {{-- Name Field --}}
                        <div class="form-group">
                            <label class="font-weight-bold">
                                Full Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                wire:model.live="name"
                                class="form-control @error('name') is-invalid @enderror"
                                placeholder="Enter full name">
                            @error('name') 
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Email Field --}}
                        <div class="form-group">
                            <label class="font-weight-bold">
                                Email Address <span class="text-danger">*</span>
                            </label>
                            <input type="email" 
                                wire:model.live="email"
                                class="form-control @error('email') is-invalid @enderror"
                                placeholder="email@example.com">
                            @error('email') 
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Password Field --}}
                        <div class="form-group">
                            <label class="font-weight-bold">
                                Password {{ $isEdit ? '' : '*' }}
                            </label>
                            <div class="input-group">
                                <input type="password"
                                    id="staff-password"
                                    wire:model.live="password"
                                    class="form-control @error('password') is-invalid @enderror"
                                    placeholder="{{ $isEdit ? 'Leave blank to keep current password' : 'Enter secure password' }}">
                                <div class="input-group-append">
                                    <button type="button"
                                        class="btn btn-outline-secondary password-toggle"
                                        data-password-toggle="staff-password"
                                        aria-label="Show password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <small class="form-text text-muted">
                                {{ $isEdit ? 'Leave blank to keep the current password. ' : '' }}Password must be at least 6 characters.
                            </small>
                        </div>

                        {{-- Confirm Password Field --}}
                        <div class="form-group">
                            <label class="font-weight-bold">
                                Confirm Password {{ $isEdit ? '' : '*' }}
                            </label>
                            <div class="input-group">
                                <input type="password"
                                    id="staff-password-confirmation"
                                    wire:model.live="password_confirmation"
                                    class="form-control @error('password_confirmation') is-invalid @enderror"
                                    placeholder="{{ $isEdit ? 'Repeat new password when changing it' : 'Repeat secure password' }}">
                                <div class="input-group-append">
                                    <button type="button"
                                        class="btn btn-outline-secondary password-toggle"
                                        data-password-toggle="staff-password-confirmation"
                                        aria-label="Show confirm password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('password_confirmation')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            @error('password') 
                                @if($message === 'The password confirmation does not match.')
                                    <small class="text-danger">{{ $message }}</small>
                                @endif
                            @enderror
                        </div>

                        {{-- Roles Field --}}
                        <div class="form-group">
                            <label class="font-weight-bold">
                                Role assignment <span class="text-danger">*</span>
                            </label>
                            <div class="d-flex flex-wrap mb-2">
                                <div class="custom-control custom-radio mr-4">
                                    <input type="radio" class="custom-control-input" id="roles-shared" wire:model.live="roleAssignmentMode" value="shared">
                                    <label class="custom-control-label" for="roles-shared">Same roles for all branches</label>
                                </div>
                                <div class="custom-control custom-radio">
                                    <input type="radio" class="custom-control-input" id="roles-custom" wire:model.live="roleAssignmentMode" value="custom">
                                    <label class="custom-control-label" for="roles-custom">Different roles per branch</label>
                                </div>
                            </div>
                            @error('roleAssignmentMode')<small class="text-danger d-block">{{ $message }}</small>@enderror

                            @if($roleAssignmentMode === 'shared')
                            <input type="text"
                                wire:model.live="roleSearch"
                                placeholder="Search roles..."
                                class="form-control form-control-sm mb-2">

                            <div class="border rounded p-2 bg-light" style="max-height: 150px; overflow-y: auto;">
                                <div class="row">
                                    @forelse($allRoles as $role)
                                        <div class="col-md-6 mb-2">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox"
                                                    class="custom-control-input"
                                                    id="role-{{ $role->id }}"
                                                    wire:model.live="selectedRoles"
                                                    value="{{ $role->name }}">
                                                <label class="custom-control-label" for="role-{{ $role->id }}">
                                                    {{ $role->name }}
                                                </label>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="col-12">
                                            <p class="text-muted text-center font-italic mb-0 small">No roles match your search</p>
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                            @error('selectedRoles')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                            @else
                                @if(empty($selectedBranchIds))
                                    <div class="alert alert-light border mb-0 small">Select the allowed branches below, then assign roles to each branch.</div>
                                @endif
                                @foreach($availableBranches->whereIn('id', array_map('intval', $selectedBranchIds)) as $branch)
                                    <div class="border rounded p-2 mb-2 bg-light">
                                        <div class="font-weight-bold small mb-2">{{ $branch->name }} <span class="text-muted">({{ $branch->code }})</span></div>
                                        <div class="row">
                                            @foreach($availableRoles as $role)
                                                <div class="col-md-6 mb-1">
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input"
                                                            id="branch-{{ $branch->id }}-role-{{ $role->id }}"
                                                            wire:model.live="branchRoles.{{ $branch->id }}"
                                                            value="{{ $role->name }}">
                                                        <label class="custom-control-label" for="branch-{{ $branch->id }}-role-{{ $role->id }}">{{ $role->name }}</label>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                        @error('branchRoles.'.$branch->id)<small class="text-danger">{{ $message }}</small>@enderror
                                    </div>
                                @endforeach
                            @endif
                        </div>

                        <hr>
                        <p class="text-uppercase text-muted font-weight-bold mb-3" style="font-size:.72rem;letter-spacing:.08em">
                            <i class="fas fa-code-branch mr-1"></i> Clinic &amp; Branch Access
                        </p>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold small">Membership Status <span class="text-danger">*</span></label>
                                    <select wire:model="membershipStatus" class="custom-select @error('membershipStatus') is-invalid @enderror">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    @error('membershipStatus')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold small">Staff ID</label>
                                    <input type="text" wire:model="staff_id" maxlength="30" class="form-control @error('staff_id') is-invalid @enderror" placeholder="e.g. EMP-0042">
                                    <small class="form-text text-muted">This clinic's own number for the person. Must be unique within this clinic.</small>
                                    @error('staff_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-md-7">
                                <label class="font-weight-bold small">Allowed Branches <span class="text-danger">*</span></label>
                                <div class="border rounded p-2 bg-light" style="max-height:150px;overflow-y:auto">
                                    @forelse($availableBranches as $branch)
                                        <div class="custom-control custom-checkbox mb-1">
                                            <input type="checkbox" class="custom-control-input" id="staff-branch-{{ $branch->id }}" wire:model.live="selectedBranchIds" value="{{ $branch->id }}">
                                            <label class="custom-control-label" for="staff-branch-{{ $branch->id }}">{{ $branch->name }} <small class="text-muted">({{ $branch->code }})</small></label>
                                        </div>
                                    @empty
                                        <span class="text-danger small">Create an active branch before adding staff.</span>
                                    @endforelse
                                </div>
                                @error('selectedBranchIds')<small class="text-danger">{{ $message }}</small>@enderror
                                @error('selectedBranchIds.*')<small class="text-danger d-block">{{ $message }}</small>@enderror
                            </div>
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label class="font-weight-bold small">Default Branch <span class="text-danger">*</span></label>
                                    <select wire:model="defaultBranchId" class="custom-select @error('defaultBranchId') is-invalid @enderror">
                                        <option value="">Select default</option>
                                        @foreach($availableBranches as $branch)
                                            @if(in_array($branch->id, array_map('intval', $selectedBranchIds)))
                                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error('defaultBranchId')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <small class="form-text text-muted">The branch opened immediately after login.</small>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <p class="text-uppercase text-muted font-weight-bold mb-3" style="font-size:.72rem;letter-spacing:.08em">
                            <i class="fas fa-id-card mr-1"></i> Staff Profile
                        </p>

                        <div class="row">
                            {{-- Phone --}}
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold small">Phone</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-phone text-muted"></i></span>
                                        </div>
                                        <input type="text"
                                            wire:model="phone"
                                            class="form-control @error('phone') is-invalid @enderror"
                                            placeholder="+63 912 345 6789">
                                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                            {{-- Gender --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold small">Gender</label>
                                    <select wire:model="gender"
                                            class="custom-select @error('gender') is-invalid @enderror">
                                        <option value="">— Select —</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            {{-- Date of Birth --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold small">Date of Birth</label>
                                    <input type="date"
                                        wire:model="date_of_birth"
                                        class="form-control @error('date_of_birth') is-invalid @enderror">
                                    @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            {{-- Hire Date --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold small">Hire Date</label>
                                    <input type="date"
                                        wire:model="hire_date"
                                        class="form-control @error('hire_date') is-invalid @enderror">
                                    @error('hire_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            {{-- Department --}}
                            <div class="col-md-12">
                                <div class="form-group mb-0">
                                    <label class="font-weight-bold small">Department</label>
                                    <input type="text"
                                        wire:model="department"
                                        class="form-control @error('department') is-invalid @enderror"
                                        placeholder="e.g. Ophthalmology, Administration">
                                    @error('department')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Modal Footer --}}
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeModal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
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
    <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg,#0f3460,#0d7377); color:#fff;">
                    <h5 class="modal-title">
                        <i class="fas fa-key mr-2"></i> Reset Password
                    </h5>
                    <button type="button" class="close" wire:click="closeResetModal" style="color:#fff; opacity:1;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <form wire:submit="doResetPassword">
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
                                style="width:48px;height:48px;background:linear-gradient(135deg,#0f3460,#0d7377);">
                                <i class="fas fa-user text-white"></i>
                            </div>
                            <div class="font-weight-bold">{{ $resetUserName }}</div>
                            <small class="text-muted">Setting a new password for this account</small>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold small">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password"
                                    id="reset-password"
                                    wire:model.live="newPassword"
                                    class="form-control @error('newPassword') is-invalid @enderror"
                                    placeholder="Min. 6 characters">
                                <div class="input-group-append">
                                    <button type="button"
                                        class="btn btn-outline-secondary password-toggle"
                                        data-password-toggle="reset-password"
                                        aria-label="Show new password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('newPassword')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <small class="form-text text-muted">Password must be at least 6 characters.</small>
                        </div>

                        <div class="form-group mb-0">
                            <label class="font-weight-bold small">Confirm Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password"
                                    id="reset-password-confirmation"
                                    wire:model.live="newPasswordConfirmation"
                                    class="form-control @error('newPasswordConfirmation') is-invalid @enderror"
                                    placeholder="Repeat new password">
                                <div class="input-group-append">
                                    <button type="button"
                                        class="btn btn-outline-secondary password-toggle"
                                        data-password-toggle="reset-password-confirmation"
                                        aria-label="Show confirm password"
                                        title="Show/hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                @error('newPasswordConfirmation')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="closeResetModal">Cancel</button>
                        <button type="submit" class="btn btn-sm text-white"
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
    <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-file-csv mr-2"></i> Bulk Import Staff
                    </h5>
                    <button type="button" class="close text-white" wire:click="closeImportModal">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="modal-body">

                    {{-- Instructions --}}
                    <div class="alert alert-info border-0 small mb-4">
                        <i class="fas fa-info-circle mr-1"></i>
                        Upload a <strong>.csv</strong> file with one staff member per row.
                        Required columns: <code>name</code>, <code>email</code>, <code>password</code>, <code>role</code>.
                        Optional: <code>phone</code>, <code>staff_id</code>, <code>gender</code>, <code>date_of_birth</code>, <code>department</code>, <code>hire_date</code>.
                        <br>
                        <button wire:click="downloadTemplate" class="btn btn-link btn-sm p-0 mt-1">
                            <i class="fas fa-download mr-1"></i> Download template CSV
                        </button>
                    </div>

                    {{-- File input --}}
                    @if(empty($importResults))
                        <div class="form-group">
                            <label class="font-weight-bold">Select CSV File <span class="text-danger">*</span></label>
                            <input type="file"
                                   wire:model.live="importFile"
                                   accept=".csv,.txt"
                                   class="form-control-file @error('importFile') is-invalid @enderror">
                            @error('importFile')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <div wire:loading wire:target="importFile" class="text-muted small mt-1">
                                <i class="fas fa-spinner fa-spin mr-1"></i> Uploading…
                            </div>
                        </div>
                    @endif

                    {{-- Results --}}
                    @if(!empty($importResults))
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="border text-center p-3" style="background:#eaf7ee;">
                                    <div class="h4 font-weight-bold text-success mb-0">{{ $importResults['created'] }}</div>
                                    <small class="text-muted">Imported</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border text-center p-3" style="background:#fdecee;">
                                    <div class="h4 font-weight-bold text-danger mb-0">{{ $importResults['skipped'] }}</div>
                                    <small class="text-muted">Skipped / Errors</small>
                                </div>
                            </div>
                        </div>

                        @if(!empty($importResults['errors']))
                            <div class="border rounded p-3 bg-light" style="max-height:200px;overflow-y:auto;">
                                @foreach($importResults['errors'] as $err)
                                    <div class="small text-danger mb-1">
                                        <i class="fas fa-exclamation-circle mr-1"></i>{{ $err }}
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <button wire:click="$set('importResults', [])" class="btn btn-sm btn-outline-secondary mt-3">
                            <i class="fas fa-redo mr-1"></i> Import Another File
                        </button>
                    @endif

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeImportModal">
                        {{ empty($importResults) ? 'Cancel' : 'Close' }}
                    </button>
                    @if(empty($importResults))
                        <button type="button"
                                class="btn btn-success"
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
