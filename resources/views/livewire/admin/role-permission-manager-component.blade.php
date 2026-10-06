<div class="clinic-ui ui-page"> {{-- single Livewire root required by Livewire 2 --}}

<div class="w-full py-6">

    {{-- Header --}}
    <div class="flex flex-wrap -mx-2 mb-6 items-center">
        <div class="min-w-0 flex-1 px-2">
            <h4 class="font-semibold text-teal-700 mb-1">Roles &amp; Permissions</h4>
            <p class="text-slate-500 text-sm mb-0">Create custom roles, define permissions, and control what each role can access.</p>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mb-4">
        <div class="card-body p-4 py-2">
            <div class="inline-flex flex-wrap gap-1">
                <button wire:click="$set('activeTab', 'roles')"
                    class="btn ui-button {{ $activeTab === 'roles' ? 'ui-button-primary' : 'ui-button-secondary' }}">
                    <i class="fas fa-user-tag mr-1"></i> Roles
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $activeTab === 'roles' ? 'light text-teal-700' : 'secondary' }} ml-1">{{ $roles->count() }}</span>
                </button>
                <button wire:click="$set('activeTab', 'permissions')"
                    class="btn ui-button {{ $activeTab === 'permissions' ? 'ui-button-primary' : 'ui-button-secondary' }}">
                    <i class="fas fa-key mr-1"></i> Permissions
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $activeTab === 'permissions' ? 'light text-teal-700' : 'secondary' }} ml-1">{{ $permissions->count() }}</span>
                </button>
                <button wire:click="$set('activeTab', 'matrix')"
                    class="btn ui-button {{ $activeTab === 'matrix' ? 'ui-button-primary' : 'ui-button-secondary' }}">
                    <i class="fas fa-table mr-1"></i> Matrix
                </button>
            </div>
        </div>
    </div>

    {{-- ══ ROLES TAB ══════════════════════════════════════════════════════════ --}}
    @if($activeTab === 'roles')
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
        <div class="card-header border-b px-4 bg-white border-slate-200 flex items-center justify-between py-4">
            <span class="font-semibold text-slate-900">All Roles</span>
            <button wire:click="openCreateRole" class="btn ui-button ui-button-sm ui-button-primary shadow-none">
                <i class="fas fa-plus mr-1"></i> New Role
            </button>
        </div>
        <div class="ui-table-wrap">
            <table class="table ui-table align-middle mb-0">
                <thead class="bg-slate-50">
                    <tr class="text-sm uppercase font-semibold text-slate-500">
                        <th class="pl-6 border-0">Role Name</th>
                        <th class="border-0">Permissions</th>
                        <th class="border-0">Users</th>
                        <th class="border-0 text-right pr-6">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($roles as $role)
                        <tr>
                            <td class="pl-6 py-4">
                                <div class="flex items-center">
                                    @php
                                        $roleColor = match($role->name) {
                                            'Super Admin' => '#6610f2',
                                            'Manager'     => '#fd7e14',
                                            'Doctor'      => '#0dcaf0',
                                            default       => '#0d6efd',
                                        };
                                    @endphp
                                    <div class="rounded-full flex items-center justify-center mr-4 shrink-0"
                                         style="width:36px;height:36px;background:{{ $roleColor }}22;">
                                        <i class="fas fa-user-tag" style="color:{{ $roleColor }};font-size:0.8rem;"></i>
                                    </div>
                                    <div>
                                        <div class="font-semibold">{{ $role->name }}</div>
                                        @if(in_array($role->name, $protectedRoles))
                                            <span class="inline-flex items-center px-1.5 py-0.5 text-xs font-semibold rounded-full" style="background:#6610f222;color:#6610f2;font-size:0.7rem;">Protected</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($role->permissions_count > 0)
                                    <span class="inline-flex items-center py-0.5 text-xs font-semibold bg-teal-100 text-teal-800 rounded-full px-2">{{ $role->permissions_count }} permission{{ $role->permissions_count !== 1 ? 's' : '' }}</span>
                                @else
                                    <span class="text-slate-500 text-sm">None assigned</span>
                                @endif
                            </td>
                            <td>
                                @if($role->users_count > 0)
                                    <span class="inline-flex items-center py-0.5 text-xs font-semibold bg-green-100 text-green-800 rounded-full px-2">{{ $role->users_count }} user{{ $role->users_count !== 1 ? 's' : '' }}</span>
                                @else
                                    <span class="text-slate-500 text-sm">No users</span>
                                @endif
                            </td>
                            <td class="pr-6 text-right whitespace-nowrap">
                                <button wire:click="openEditRole({{ $role->id }})"
                                        class="btn ui-button ui-button-sm ui-button-secondary shadow-none mr-1"
                                        title="Edit role &amp; permissions">
                                    <i class="fas fa-edit mr-1"></i> Edit
                                </button>
                                <button wire:click="openRoleUsers({{ $role->id }})"
                                        class="btn ui-button ui-button-sm ui-button-secondary shadow-none mr-1"
                                        title="Assign users">
                                    <i class="fas fa-users mr-1"></i> Users
                                </button>
                                @if(!in_array($role->name, $protectedRoles))
                                    <button onclick="confirmDeleteRole({{ $role->id }}, {{ json_encode($role->name) }}, {{ $role->users_count }})"
                                            class="btn ui-button ui-button-sm ui-button-danger shadow-none"
                                            title="Delete role">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                @else
                                    <button class="btn ui-button ui-button-sm ui-button-secondary shadow-none" disabled title="Protected role">
                                        <i class="fas fa-lock"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-12 text-slate-500">
                                <i class="fas fa-user-tag fa-2x mb-2 block opacity-50"></i>
                                No roles found. Create one to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ══ PERMISSIONS TAB ════════════════════════════════════════════════════ --}}
    @if($activeTab === 'permissions')
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0 mb-4">
        <div class="card-header border-b px-4 bg-white border-slate-200 py-4">
            <div class="font-semibold text-slate-900"><i class="fas fa-layer-group mr-1 text-teal-700"></i> Permission Presets</div>
            <div class="text-slate-500 text-sm mt-1">Create standard permission groups, then assign them from the Roles tab.</div>
        </div>
        <div class="card-body p-4">
            <div class="flex flex-wrap -mx-2">
                @foreach($permissionPresets as $group => $presetPermissions)
                    @php
                        $existing = $permissions->whereIn('name', $presetPermissions)->count();
                        $missing = count($presetPermissions) - $existing;
                    @endphp
                    <div class="w-full lg:w-4/12 md:w-6/12 px-2 mb-4">
                        <div class="border border-slate-200 rounded-md p-4 h-full" style="background:#f8fafc">
                            <div class="flex justify-between items-start mb-2">
                                <div class="font-semibold">{{ $group }}</div>
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold badge-{{ $missing > 0 ? 'warning' : 'success' }}">
                                    {{ $missing > 0 ? $missing . ' missing' : 'Ready' }}
                                </span>
                            </div>
                            <div class="text-sm text-slate-500 mb-4">{{ implode(', ', $presetPermissions) }}</div>
                            <button type="button" class="btn ui-button ui-button-sm ui-button-secondary"
                                    wire:click="createPermissionPreset('{{ $group }}')">
                                <i class="fas fa-plus mr-1"></i> Apply Preset
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
        <div class="card-header border-b px-4 bg-white border-slate-200 flex items-center justify-between py-4">
            <div>
                <span class="font-semibold text-slate-900">All Permissions</span>
                <div class="text-slate-500 text-sm mt-1">Use lowercase with spaces, e.g. <code>manage users</code>, <code>view reports</code></div>
            </div>
            <button wire:click="openCreatePermission" class="btn ui-button ui-button-sm ui-button-primary shadow-none">
                <i class="fas fa-plus mr-1"></i> New Permission
            </button>
        </div>
        <div class="ui-table-wrap">
            <table class="table ui-table align-middle mb-0">
                <thead class="bg-slate-50">
                    <tr class="text-sm uppercase font-semibold text-slate-500">
                        <th class="pl-6 border-0">Permission</th>
                        <th class="border-0">Assigned to Roles</th>
                        <th class="border-0">Enforced In App</th>
                        <th class="border-0 text-right pr-6">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($permissions as $perm)
                        <tr>
                            <td class="pl-6 py-4">
                                <div class="flex items-center">
                                    <div class="rounded-full flex items-center justify-center mr-4 shrink-0"
                                         style="width:36px;height:36px;background:#19875422;">
                                        <i class="fas fa-key" style="color:#198754;font-size:0.8rem;"></i>
                                    </div>
                                    <code class="text-slate-900" style="font-size:0.85rem;">{{ $perm->name }}</code>
                                </div>
                            </td>
                            <td>
                                @forelse($perm->roles as $r)
                                    <span class="inline-flex items-center py-0.5 text-xs font-semibold bg-slate-100 text-slate-700 rounded-full px-2 mr-1">{{ $r->name }}</span>
                                @empty
                                    <span class="text-slate-500 text-sm">Not assigned</span>
                                @endforelse
                            </td>
                            <td>
                                @php $usage = $permissionUsage[$perm->name] ?? []; @endphp
                                @if(count($usage) > 0)
                                    <span class="inline-flex items-center py-0.5 text-xs font-semibold bg-green-100 text-green-800 rounded-full px-2" title="{{ implode(', ', $usage) }}">
                                        Used {{ count($usage) }}x
                                    </span>
                                @else
                                    <span class="inline-flex items-center py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 rounded-full px-2" title="This permission exists but is not checked by route middleware, @@can, or user can() calls yet.">
                                        Not enforced yet
                                    </span>
                                @endif
                            </td>
                            <td class="pr-6 text-right whitespace-nowrap">
                                <button wire:click="openEditPermission({{ $perm->id }})"
                                        class="btn ui-button ui-button-sm ui-button-secondary shadow-none mr-1"
                                        title="Rename permission">
                                    <i class="fas fa-edit mr-1"></i> Edit
                                </button>
                                <button onclick="confirmDeletePermission({{ $perm->id }}, {{ json_encode($perm->name) }}, {{ $perm->roles->count() }})"
                                        class="btn ui-button ui-button-sm ui-button-danger shadow-none"
                                        title="Delete permission">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-12 text-slate-500">
                                <i class="fas fa-key fa-2x mb-2 block opacity-50"></i>
                                No permissions defined yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($activeTab === 'matrix')
    <div class="card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm border-0">
        <div class="card-header border-b px-4 bg-white border-slate-200 py-4">
            <div class="font-semibold text-slate-900"><i class="fas fa-table mr-1 text-teal-700"></i> Role Permission Matrix</div>
            <div class="text-slate-500 text-sm mt-1">Green means the permission is assigned to that role. Use the Roles tab to make changes.</div>
        </div>
        <div class="ui-table-wrap">
            <table class="table ui-table ui-table-sm mb-0 role-matrix-table">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="whitespace-nowrap">Permission</th>
                        @foreach($roles as $role)
                            <th class="text-center whitespace-nowrap">
                                {{ $role->name }}
                                @if(in_array($role->name, $protectedRoles))
                                    <i class="fas fa-lock text-slate-500 ml-1" title="Protected role"></i>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($permissions as $perm)
                        <tr>
                            <td class="whitespace-nowrap">
                                <code>{{ $perm->name }}</code>
                                @if(empty($permissionUsage[$perm->name] ?? []))
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 ml-2">Not enforced</span>
                                @endif
                            </td>
                            @foreach($roles as $role)
                                @php $hasPermission = $role->permissions->contains('id', $perm->id); @endphp
                                <td class="text-center">
                                    @if($hasPermission)
                                        <span class="matrix-check"><i class="fas fa-check"></i></span>
                                    @else
                                        <span class="text-slate-500">&mdash;</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $roles->count() + 1 }}" class="text-center text-slate-500 py-12">
                                No permissions defined yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>{{-- /.container-fluid --}}

{{-- ══ ROLE MODAL ══════════════════════════════════════════════════════════════ --}}
{{-- wire:ignore.self: Bootstrap controls the modal's own attributes (display, aria-*) --}}
{{-- All interactive buttons use onclick + @this.call() — wire:click is unreliable    --}}
{{-- after Bootstrap moves the modal element to <body>.                               --}}
<div wire:ignore.self class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="roleModal" tabindex="-1" role="dialog">
    <div class="mx-auto my-8 w-full max-w-3xl" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl border-0 shadow">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-teal-700 text-white">
                <h5 class="text-base font-semibold" id="roleModalTitle">
                    <i class="fas fa-user-tag mr-2"></i> New Role
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="p-4">

                <div class="mb-4">
                    <label class="font-semibold text-sm">Role Name <span class="text-red-700">*</span></label>
                    <input wire:model="roleName"
                           id="roleNameInput"
                           type="text"
                           class="form-control ui-input @error('roleName') is-invalid @enderror"
                           placeholder="e.g. Pharmacist, Lab Technician, Receptionist">
                    @error('roleName')
                        <div class="ui-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label class="font-semibold text-sm">
                        Dashboard After Login
                        <span class="text-slate-500 font-normal">— where this role lands on login</span>
                    </label>
                    <select wire:model="dashboardRoute" class="form-control ui-input">
                        <option value="">— User Profile (default fallback) —</option>
                        <option value="admin.dashboard">Admin Dashboard</option>
                        <option value="doctor.dashboard">Doctor Dashboard</option>
                        <option value="secretary.dashboard">Secretary Dashboard</option>
                        <option value="cashier.seller-desk">Cashier POS Desk</option>
                        <option value="user.profile">User Profile Page</option>
                    </select>
                    <small class="text-slate-500">Only applies to custom roles; built-in roles always use their own dashboard.</small>
                </div>

                @if($editingRoleId && in_array($roleName, $protectedRoles))
                    <div class="rounded-lg border px-3 py-2 text-sm border-amber-200 bg-amber-50 text-amber-900 border-0">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>{{ $roleName }}</strong> is a protected system role. Its name and deletion are locked, but permission changes can still affect access throughout the app.
                    </div>
                @endif

                <div class="mb-4">
                    <label class="font-semibold text-sm block mb-2">Role Templates</label>
                    <div class="flex flex-wrap" style="gap:.4rem">
                        @foreach($roleTemplates as $template => $templatePermissions)
                            <button type="button" class="btn ui-button ui-button-sm ui-button-secondary"
                                    wire:click="applyRoleTemplate('{{ $template }}')"
                                    title="{{ implode(', ', $templatePermissions) }}">
                                <i class="fas fa-magic mr-1"></i>{{ $template }}
                            </button>
                        @endforeach
                    </div>
                    <small class="text-slate-500 block mt-1">Templates add recommended permissions to the current selection. Review before saving.</small>
                </div>

                <div class="mb-0">
                    <label class="font-semibold text-sm block mb-2">
                        Permissions
                        <span class="text-slate-500 font-normal">— select what this role can do</span>
                    </label>

                    @if($permissions->isEmpty())
                        <div class="rounded-lg border px-3 py-2 text-sm border-sky-200 bg-sky-50 text-sky-900 border-0 mb-0">
                            <i class="fas fa-info-circle mr-1"></i>
                            No permissions exist yet. Create some in the <strong>Permissions</strong> tab first.
                        </div>
                    @else
                        <div class="flex flex-wrap -mx-2">
                            @foreach($permissions as $perm)
                                <div class="w-full md:w-6/12 lg:w-4/12 px-2">
                                    <label class="flex items-center p-2 rounded-md mb-1"
                                           style="cursor:pointer;"
                                           onmouseover="this.style.background='#f0f4ff'"
                                           onmouseout="this.style.background=''">
                                        <input type="checkbox"
                                               wire:model.live="selectedPermissions"
                                               value="{{ $perm->id }}"
                                               class="mr-2"
                                               style="width:16px;height:16px;flex-shrink:0;">
                                        <code class="text-sm">{{ $perm->name }}</code>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 text-slate-500 text-sm">
                            <i class="fas fa-info-circle mr-1"></i>
                            <strong>Super Admin</strong> always has full access regardless of this list.
                        </div>
                    @endif
                </div>

                @if($editingRoleId && (count($rolePermissionPreview['added']) || count($rolePermissionPreview['removed'])))
                    <div class="mt-4 p-4 rounded-md" style="background:#f8fafc;border:1px solid #e5e7eb">
                        <div class="font-semibold text-sm mb-2"><i class="fas fa-clipboard-list mr-1 text-teal-700"></i> Save Preview</div>
                        @if(count($rolePermissionPreview['added']))
                            <div class="text-sm mb-1">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800 mr-1">Added</span>
                                {{ implode(', ', $rolePermissionPreview['added']) }}
                            </div>
                        @endif
                        @if(count($rolePermissionPreview['removed']))
                            <div class="text-sm">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800 mr-1">Removed</span>
                                {{ implode(', ', $rolePermissionPreview['removed']) }}
                            </div>
                        @endif
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3 bg-slate-50">
                <button type="button" class="btn ui-button ui-button-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn ui-button ui-button-primary" id="roleSaveBtn" onclick="submitRole()">
                    <i class="fas fa-save mr-1"></i>
                    <span id="roleSaveBtnText">Create Role</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- ══ PERMISSION MODAL ════════════════════════════════════════════════════════ --}}
<div wire:ignore.self class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="roleUsersModal" tabindex="-1" role="dialog">
    <div class="mx-auto my-8 w-full max-w-3xl" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl border-0 shadow">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-sky-600 text-white">
                <h5 class="text-base font-semibold">
                    <i class="fas fa-users mr-2"></i> Users in {{ $managingRoleName ?: 'Role' }}
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="p-4">
                <div class="flex flex-wrap -mx-2 items-end mb-4">
                    <div class="min-w-0 flex-1 px-2">
                        <label class="font-semibold text-sm">Assign User</label>
                        <select wire:model="userToAssign" class="form-control ui-input @error('userToAssign') is-invalid @enderror">
                            <option value="">Choose staff member...</option>
                            @foreach($assignableUsers as $user)
                                <option value="{{ $user->id }}">
                                    {{ $user->name }} - {{ $user->email }} @if($user->roles->isNotEmpty())({{ $user->roles->pluck('name')->implode(', ') }})@endif
                                </option>
                            @endforeach
                        </select>
                        @error('userToAssign')
                            <div class="ui-error">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="w-auto px-2">
                        <button type="button" class="btn ui-button ui-button-primary font-semibold" wire:click="assignUserToManagedRole">
                            <i class="fas fa-user-plus mr-1"></i> Assign
                        </button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                    @forelse(($managingRole?->users ?? collect()) as $user)
                        <div class="list-group-item block w-full border-b border-slate-100 px-3 py-2 text-left flex items-center justify-between">
                            <div>
                                <div class="font-semibold">{{ $user->name }}</div>
                                <div class="text-slate-500 text-sm">{{ $user->email }} | {{ $user->roles->pluck('name')->implode(', ') ?: 'No role' }}</div>
                            </div>
                            <button type="button" class="btn ui-button ui-button-sm ui-button-danger"
                                    wire:click="removeUserFromManagedRole({{ $user->id }})"
                                    @if($managingRoleName === 'Super Admin' && auth()->id() === $user->id) disabled @endif>
                                <i class="fas fa-user-minus mr-1"></i> Remove
                            </button>
                        </div>
                    @empty
                        <div class="list-group-item block w-full border-b border-slate-100 px-3 text-left text-center text-slate-500 py-6">
                            No users assigned to this role.
                        </div>
                    @endforelse
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3 bg-slate-50">
                <button type="button" class="btn ui-button ui-button-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div wire:ignore.self class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 hidden" id="permissionModal" tabindex="-1" role="dialog">
    <div class="mx-auto my-8 w-full max-w-lg" role="document">
        <div class="overflow-hidden rounded-xl bg-white text-slate-800 shadow-xl border-0 shadow">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 bg-green-600 text-white">
                <h5 class="text-base font-semibold" id="permissionModalTitle">
                    <i class="fas fa-key mr-2"></i> New Permission
                </h5>
                <button type="button" class="text-xl leading-none hover:text-slate-800 text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="p-4">
                <div class="mb-2">
                    <label class="font-semibold text-sm">Permission Name <span class="text-red-700">*</span></label>
                    <input wire:model="permissionName"
                           id="permissionNameInput"
                           type="text"
                           class="form-control ui-input @error('permissionName') is-invalid @enderror"
                           placeholder="e.g. manage billing, view reports, process refunds">
                    @error('permissionName')
                        <div class="ui-error">{{ $message }}</div>
                    @enderror
                </div>
                <div class="text-sm text-slate-500">
                    <i class="fas fa-lightbulb mr-1 text-amber-600"></i>
                    Use lowercase with spaces. Assign it to roles via the <strong>Edit Role</strong> dialog.
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3 bg-slate-50">
                <button type="button" class="btn ui-button ui-button-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn ui-button ui-button-primary" id="permissionSaveBtn" onclick="submitPermission()">
                    <i class="fas fa-save mr-1"></i>
                    <span id="permissionSaveBtnText">Create Permission</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ── Modal open/close events ───────────────────────────────────────────────
    // isEdit is passed in event.detail from the PHP component, not from a Blade expression,
    // so this listener only runs once on page-load and always reads fresh data from the event.
    window.addEventListener('show-roleModal', function (e) {
        var isEdit = e.detail && e.detail.isEdit;
        document.getElementById('roleModalTitle').innerHTML =
            '<i class="fas fa-user-tag mr-2"></i> ' + (isEdit ? 'Edit Role' : 'New Role');
        document.getElementById('roleSaveBtnText').textContent = isEdit ? 'Save Changes' : 'Create Role';
        var saveBtn = document.getElementById('roleSaveBtn');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i> <span id="roleSaveBtnText">' +
            (isEdit ? 'Save Changes' : 'Create Role') + '</span>';
        uiModal('roleModal', true);
    });

    window.addEventListener('hide-roleModal', function () { uiModal('roleModal', false); });

    window.addEventListener('show-permissionModal', function (e) {
        var isEdit = e.detail && e.detail.isEdit;
        document.getElementById('permissionModalTitle').innerHTML =
            '<i class="fas fa-key mr-2"></i> ' + (isEdit ? 'Edit Permission' : 'New Permission');
        var saveBtn = document.getElementById('permissionSaveBtn');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i> <span id="permissionSaveBtnText">' +
            (isEdit ? 'Save Changes' : 'Create Permission') + '</span>';
        uiModal('permissionModal', true);
    });

    window.addEventListener('hide-permissionModal', function () { uiModal('permissionModal', false); });
    window.addEventListener('show-roleUsersModal', function () { uiModal('roleUsersModal', true); });

    // ── Save actions (called from modal buttons) ──────────────────────────────
    function submitRole() {
        var btn = document.getElementById('roleSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving…';
        @this.call('saveRole').then(function () {
            // On validation error Livewire resolves the promise without hiding the modal;
            // re-enable the button so the user can correct and retry.
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save mr-1"></i> Save';
        });
    }

    function submitPermission() {
        var btn = document.getElementById('permissionSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving…';
        @this.call('savePermission').then(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save mr-1"></i> Save';
        });
    }

    // ── Delete confirmations ──────────────────────────────────────────────────
    function confirmDeleteRole(roleId, roleName, userCount) {
        if (userCount > 0) {
            window.appAlert('Cannot delete role',
                'The role ' + roleName + ' is assigned to ' + userCount + ' user(s).\nRe-assign those users to a different role before deleting.', 'error');
            return;
        }
        window.appConfirm('The role ' + roleName + ' will be permanently removed.', { title: 'Delete role?', confirmText: 'Yes, delete', danger: true })
            .then(function (ok) { if (ok) @this.call('deleteRole', roleId); });
    }

    function confirmDeletePermission(permId, permName, roleCount) {
        var note = roleCount > 0 ? '\nAssigned to ' + roleCount + ' role(s) — it will be removed from all of them.' : '';
        window.appConfirm('Permission ' + permName + ' will be permanently deleted.' + note, { title: 'Delete permission?', confirmText: 'Yes, delete', danger: true })
            .then(function (ok) { if (ok) @this.call('deletePermission', permId); });
    }
</script>

<style>
    .role-matrix-table th,
    .role-matrix-table td {
        vertical-align: middle;
        font-size: .84rem;
    }
    .matrix-check {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #dcfce7;
        color: #15803d;
        font-size: .75rem;
    }
</style>

</div>{{-- end single Livewire root --}}
