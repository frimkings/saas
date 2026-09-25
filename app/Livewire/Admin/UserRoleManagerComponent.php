<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\User;
use App\Services\LicenseService;
use App\Support\Feature;
use Spatie\Permission\Models\Role;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Clinic;
use App\Support\Tenancy\ClinicMembershipManager;
use App\Support\Tenancy\TenantContext;

class UserRoleManagerComponent extends Component
{
    use WithPagination, WithFileUploads;

    public $name, $email, $password, $password_confirmation, $userId;
    public $selectedRoles = [];
    public string $roleAssignmentMode = 'shared';
    public array $branchRoles = [];
    public array $selectedBranchIds = [];
    public ?int $defaultBranchId = null;
    public string $membershipStatus = 'active';
    public $isOpen = false;
    public $isEdit = false;

    // Extended profile fields (admin-managed)
    public string $phone        = '';
    /** Staff ID at the current clinic (stored on the clinic membership, unique within the clinic). */
    public string $staff_id     = '';
    public string $gender       = '';
    public string $date_of_birth= '';
    public string $department   = '';
    public string $hire_date    = '';

    // Admin password reset
    public $resetUserId;
    public $resetUserName;
    public $newPassword;
    public $newPasswordConfirmation;
    public $isResetOpen = false;

    // CSV import
    public $importFile = null;
    public $isImportOpen = false;
    public array $importResults = [];
    
    // Enhanced Search & Filter Properties
    public $search = '';
    public $roleSearch = '';
    public $filterRole = '';
    public $filterStatus = '';
    public $filterEmailVerified = '';
    public $filterDateFrom = '';
    public $filterDateTo = '';
    public $sortField = 'created_at';
    public $sortDirection = 'desc';
    public $perPage = 10;
    public $showFilters = false;

    // Former staff ('left') are hidden and can no longer be edited by the clinic they left.
    private const CURRENT_MEMBER_STATUSES = ['active', 'inactive'];
    private const SORT_WHITELIST = ['name', 'email', 'created_at', 'is_active', 'staff_id', 'id'];

    public function mount(): void
    {
        abort_if(!$this->canManageUsers(), 403);
    }

    protected function rules(): array
    {
        return [
            'name'           => 'required|min:3',
            'email'          => 'required|email|unique:users,email,' . $this->userId,
            'password'       => $this->isEdit
                ? ['nullable', 'confirmed', Password::min(6)]
                : ['required', 'confirmed', Password::min(6)],
            'password_confirmation' => $this->isEdit ? 'nullable' : 'required',
            'roleAssignmentMode' => 'required|in:shared,custom',
            'selectedRoles'  => 'required_if:roleAssignmentMode,shared|array',
            'selectedRoles.*' => ['string', Rule::exists('roles', 'name')],
            'branchRoles' => 'array',
            'branchRoles.*' => 'array',
            'branchRoles.*.*' => ['string', Rule::exists('roles', 'name')],
            'selectedBranchIds' => ['required', 'array', 'min:1'],
            'selectedBranchIds.*' => ['integer', Rule::exists('branches', 'id')->where('clinic_id', $this->currentClinic()->id)],
            'defaultBranchId' => ['required', 'integer', Rule::exists('branches', 'id')->where('clinic_id', $this->currentClinic()->id)],
            'membershipStatus' => 'required|in:active,inactive',
            'phone'          => 'nullable|string|max:25',
            // Each clinic numbers its own staff: unique within this clinic only.
            'staff_id'       => ['nullable', 'string', 'max:30', Rule::unique('clinic_user', 'staff_identifier')
                ->where('clinic_id', $this->currentClinic()->id)->ignore($this->userId, 'user_id')],
            'gender'         => 'nullable|in:Male,Female,Other',
            'date_of_birth'  => 'nullable|date|before:today',
            'department'     => 'nullable|string|max:100',
            'hire_date'      => 'nullable|date',
        ];
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFilterRole()
    {
        $this->resetPage();
    }

    public function updatingFilterStatus()
    {
        $this->resetPage();
    }

    public function updatingFilterEmailVerified()
    {
        $this->resetPage();
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    public function sortBy($field): void
    {
        if (!in_array($field, self::SORT_WHITELIST, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function clearFilters()
    {
        $this->reset(['search', 'filterRole', 'filterStatus', 'filterEmailVerified', 'filterDateFrom', 'filterDateTo']);
        $this->resetPage();
    }

    // Current members of this clinic with their membership status here (not the account-wide flag).
    private function staffQuery(Clinic $clinic)
    {
        return User::query()
            ->select('users.*')
            ->addSelect(['membership_status' => DB::table('clinic_user')->select('status')
                ->whereColumn('clinic_user.user_id', 'users.id')->where('clinic_user.clinic_id', $clinic->id)->limit(1)])
            ->addSelect(['clinic_staff_id' => DB::table('clinic_user')->select('staff_identifier')
                ->whereColumn('clinic_user.user_id', 'users.id')->where('clinic_user.clinic_id', $clinic->id)->limit(1)])
            ->whereHas('clinics', fn ($query) => $query->whereKey($clinic->id)->whereIn('clinic_user.status', self::CURRENT_MEMBER_STATUSES))
            ->with(['roles', 'latestLogin'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('email', 'like', '%' . $this->search . '%')
                      ->orWhereExists(fn ($m) => $m->from('clinic_user')->whereColumn('clinic_user.user_id', 'users.id')
                          ->where('clinic_user.clinic_id', $this->currentClinic()->id)
                          ->where('clinic_user.staff_identifier', 'like', '%' . $this->search . '%'));
                });
            })
            ->when($this->filterRole, function ($query) {
                $query->whereHas('roles', fn ($q) => $q->where('name', $this->filterRole));
            })
            ->when($this->filterStatus !== '', function ($query) use ($clinic) {
                $query->whereHas('clinics', fn ($q) => $q->whereKey($clinic->id)
                    ->where('clinic_user.status', $this->filterStatus === '1' ? 'active' : 'inactive'));
            })
            ->when($this->filterEmailVerified !== '', function ($query) {
                $this->filterEmailVerified === '1' ? $query->whereNotNull('email_verified_at') : $query->whereNull('email_verified_at');
            })
            ->when($this->filterDateFrom, fn ($query) => $query->whereDate('created_at', '>=', $this->filterDateFrom))
            ->when($this->filterDateTo, fn ($query) => $query->whereDate('created_at', '<=', $this->filterDateTo))
            ->orderBy(match ($this->sortField) { 'is_active' => 'membership_status', 'staff_id' => 'clinic_staff_id', default => $this->sortField }, $this->sortDirection);
    }

    public function render()
    {
        $clinic = $this->currentClinic();
        $users = $this->staffQuery($clinic)->paginate($this->perPage);

        $allRoles = Role::where('name', 'like', '%' . $this->roleSearch . '%')->get();
        $availableRoles = Role::all();

        $clinicUsers = User::query()->whereHas('clinics', fn ($query) => $query->whereKey($clinic->id)->whereIn('clinic_user.status', self::CURRENT_MEMBER_STATUSES));
        $memberships = DB::table('clinic_user')->where('clinic_id', $clinic->id);
        $stats = [
            'total' => (clone $clinicUsers)->count(),
            'active' => (clone $memberships)->where('status', 'active')->count(),
            'inactive' => (clone $memberships)->where('status', 'inactive')->count(),
            'verified' => (clone $clinicUsers)->whereNotNull('email_verified_at')->count(),
        ];

        return view('livewire.admin.user-role-manager-component', [
            'users' => $users,
            'allRoles' => $allRoles,
            'availableRoles' => $availableRoles,
            'stats' => $stats,
            'availableBranches' => $clinic->branches()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
// dd(auth()->user()->getRoleNames()),

        ])->layout('layouts.admin.admin-layout');
        // Add this to UserRoleManagerComponent.php render() to test:

    }


    public function create()
    {
        $this->resetInputFields();
        $this->isEdit = false;
        $default = $this->currentClinic()->branches()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
        $this->selectedBranchIds = $default ? [$default->id] : [];
        $this->defaultBranchId = $default?->id;
        $this->roleAssignmentMode = 'shared';
        $this->branchRoles = [];
        $this->isOpen = true;
    }

    public function edit($id)
    {
        $user = $this->managedUser($id);
        $this->userId        = $id;
        $this->name          = $user->name;
        $this->email         = $user->email;
        $this->phone         = $user->phone        ?? '';
        $this->gender        = $user->gender        ?? '';
        $this->date_of_birth = $user->date_of_birth ? $user->date_of_birth->format('Y-m-d') : '';
        $this->department    = $user->department    ?? '';
        $this->hire_date     = $user->hire_date     ? $user->hire_date->format('Y-m-d') : '';
        $membership = $user->clinics()->whereKey($this->currentClinic()->id)->firstOrFail()->pivot;
        $this->membershipStatus = $membership->status;
        $this->staff_id = $membership->staff_identifier ?? '';
        $branchMemberships = $user->branches()->where('branches.clinic_id', $this->currentClinic()->id)->get();
        $this->selectedBranchIds = $branchMemberships->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->defaultBranchId = $branchMemberships->firstWhere('pivot.is_default', true)?->id
            ?? $branchMemberships->first()?->id;
        $roleRows = DB::table('branch_user_role')
            ->join('roles', 'roles.id', '=', 'branch_user_role.role_id')
            ->where('branch_user_role.user_id', $user->id)
            ->whereIn('branch_user_role.branch_id', $this->selectedBranchIds)
            ->get(['branch_user_role.branch_id', 'roles.name']);
        $this->branchRoles = [];
        foreach ($this->selectedBranchIds as $branchId) {
            $this->branchRoles[$branchId] = $roleRows->where('branch_id', $branchId)->pluck('name')->values()->all();
        }
        if ($roleRows->isEmpty()) {
            $legacyRoles = collect(explode(',', (string) $membership->clinic_role))->map(fn ($role) => trim($role))->filter()->values()->all();
            $legacyRoles = $legacyRoles ?: $user->roles->pluck('name')->all();
            foreach ($this->selectedBranchIds as $branchId) {
                $this->branchRoles[$branchId] = $legacyRoles;
            }
        }
        $roleSets = collect($this->branchRoles)->map(function ($roles) {
            sort($roles);
            return implode('|', $roles);
        })->unique();
        $this->roleAssignmentMode = $roleSets->count() <= 1 ? 'shared' : 'custom';
        $this->selectedRoles = $this->roleAssignmentMode === 'shared'
            ? array_values($this->branchRoles[$this->selectedBranchIds[0] ?? 0] ?? [])
            : [];
        $this->isEdit        = true;
        $this->isOpen        = true;
    }

    public function store()
    {
        abort_if(!$this->canManageUsers(), 403);

        if (!$this->isEdit && config('tenancy.enabled')) {
            try { app(\App\Services\SubscriptionQuotaService::class)->assertUserAvailable($this->currentClinic()); }
            catch (\Illuminate\Validation\ValidationException $e) { $this->addError('email', $e->errors()['subscription'][0]); return; }
        }

        // Free tier: max 3 user accounts
        if (!$this->isEdit && !LicenseService::has(Feature::UNLIMITED_USERS)) {
            $count = $this->currentClinic()->users()->count();
            if ($count >= 3) {
                $this->dispatch('show-alert', ...[
                    'type'    => 'error',
                    'message' => 'Free tier is limited to 3 user accounts. Upgrade to Pro to add more users.',
                ]);
                return;
            }
        }

        $this->validate();
        if ($this->isEdit) {
            $this->managedUser((int) $this->userId);
        }

        if (! in_array((int) $this->defaultBranchId, array_map('intval', $this->selectedBranchIds), true)) {
            $this->addError('defaultBranchId', 'The default branch must be one of the allowed branches.');
            return;
        }

        $rolesByBranch = $this->validatedRolesByBranch();
        if ($rolesByBranch === null) {
            return;
        }

        DB::transaction(function () use ($rolesByBranch) {
        $user = User::updateOrCreate(['id' => $this->userId], [
            'name'          => $this->name,
            'email'         => $this->email,
            'password'      => $this->password ? Hash::make($this->password) : $this->managedUser((int) $this->userId)->password,
            'phone'         => $this->phone        ?: null,
            'gender'        => $this->gender        ?: null,
            'date_of_birth' => $this->date_of_birth ?: null,
            'department'    => $this->department    ?: null,
            'hire_date'     => $this->hire_date     ?: null,
        ]);

        $allAssignedRoles = collect($rolesByBranch)->flatten()->unique()->values()->all();
        $user->syncRoles($allAssignedRoles);
        $clinic = $this->currentClinic();
        $clinic->users()->syncWithoutDetaching([$user->id => [
            'status' => $this->membershipStatus,
            'is_default' => DB::table('clinic_user')->where('user_id', $user->id)->where('clinic_id', $clinic->id)->value('is_default')
                ?? ! $user->clinics()->wherePivot('status', 'active')->exists(),
            'staff_identifier' => trim($this->staff_id) ?: null,
            'clinic_role' => implode(', ', $allAssignedRoles),
            'invited_by' => auth()->id(),
            'joined_at' => now(),
            'left_at' => $this->membershipStatus === 'inactive' ? now() : null,
        ]]);

        $clinicBranchIds = $clinic->branches()->pluck('id');
        DB::table('branch_user_role')->where('user_id', $user->id)->whereIn('branch_id', $clinicBranchIds)->delete();
        DB::table('branch_user')->where('user_id', $user->id)->whereIn('branch_id', $clinicBranchIds)->delete();
        foreach (array_map('intval', $this->selectedBranchIds) as $branchId) {
            DB::table('branch_user')->insert([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'status' => $this->membershipStatus,
                'is_default' => $branchId === (int) $this->defaultBranchId,
                'joined_at' => now(),
                'left_at' => $this->membershipStatus === 'inactive' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $roleIds = Role::whereIn('name', $rolesByBranch[$branchId])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('branch_user_role')->insert([
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        });
        $this->dispatch('notify', ...['type' => 'success', 'message' => $this->userId ? 'Staff updated successfully.' : 'New staff member registered.']);
        $this->closeModal();
    }

    public function toggleStatus($id)
    {
        if (auth()->id() === $id) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Security check: You cannot deactivate your own account.']);
            return;
        }
        // Deactivation is per clinic: the account and the person's other clinics are untouched.
        $user = $this->managedUser((int) $id);
        $clinic = $this->currentClinic();
        $status = DB::table('clinic_user')->where('clinic_id', $clinic->id)->where('user_id', $user->id)->value('status') === 'active' ? 'inactive' : 'active';
        $this->setMembershipStatus($user, $status);
        $this->dispatch('notify', ...['type' => 'success', 'message' => $status === 'active'
            ? 'Staff member reactivated in this clinic.'
            : 'Staff member deactivated in this clinic. Their access to other clinics is unchanged.']);
    }

    public function export()
    {
        abort_if(!$this->canManageUsers(), 403);

        $fileName = 'clinic_staff_' . now()->format('Y-m-d_His') . '.csv';

        $sanitize = static function ($v): string {
            $v = (string) $v;
            return ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
        };

        $query = $this->staffQuery($this->currentClinic());

        AuditTrail::record('staff.exported', 'Staff list exported to CSV', null, [], [], null, true);

        return Response::streamDownload(function () use ($query, $sanitize) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Staff ID', 'Name', 'Email', 'Phone', 'Gender', 'DOB', 'Department', 'Hire Date', 'Roles', 'Status', 'Last Login', 'Last IP', 'Member Since']);

            $query->chunkById(500, function ($chunk) use ($file, $sanitize) {
                foreach ($chunk as $user) {
                    fputcsv($file, array_map($sanitize, [
                        $user->id,
                        $user->clinic_staff_id ?? '—',
                        $user->name,
                        $user->email,
                        $user->phone         ?? '—',
                        $user->gender        ?? '—',
                        $user->date_of_birth ? $user->date_of_birth->format('Y-m-d') : '—',
                        $user->department    ?? '—',
                        $user->hire_date     ? $user->hire_date->format('Y-m-d') : '—',
                        $user->roles->pluck('name')->implode(' | '),
                        $user->membership_status === 'active' ? 'Active' : 'Inactive',
                        $user->latestLogin ? $user->latestLogin->login_at->toDateTimeString() : 'Never',
                        $user->latestLogin ? $user->latestLogin->ip_address : 'N/A',
                        $user->created_at->toDateTimeString(),
                    ]));
                }
            });
            fclose($file);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function delete($id)
    {
        abort_if(!$this->canManageUsers(), 403);
        if (auth()->id() === $id) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Action denied: Cannot delete self.']);
            return;
        }
        // Removal ends this clinic's membership only. The account survives for the person's
        // other (or future) clinics, and records they created here keep their author.
        $user = $this->managedUser((int) $id);
        DB::transaction(function () use ($user) {
            $this->setMembershipStatus($user, 'left');
            $branchIds = $this->currentClinic()->branches()->pluck('id');
            DB::table('branch_user_role')->where('user_id', $user->id)->whereIn('branch_id', $branchIds)->delete();
            DB::table('clinic_user')->where('clinic_id', $this->currentClinic()->id)->where('user_id', $user->id)->update(['is_default' => false]);
        });
        app(ClinicMembershipManager::class)->ensureDefault($user);
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Staff member removed from this clinic. Their records here are kept.']);
    }

    private function setMembershipStatus(User $user, string $status): void
    {
        $clinic = $this->currentClinic();
        $values = ['status' => $status, 'left_at' => $status === 'active' ? null : now(), 'updated_at' => now()];
        DB::transaction(function () use ($clinic, $user, $values) {
            DB::table('clinic_user')->where('clinic_id', $clinic->id)->where('user_id', $user->id)->update($values);
            DB::table('branch_user')->where('user_id', $user->id)->whereIn('branch_id', $clinic->branches()->pluck('id'))->update($values);
        });
    }

    public function closeModal() 
    { 
        $this->isOpen = false; 
        $this->resetInputFields(); 
    }

    private function resetInputFields(): void
    {
        $this->reset([
            'name', 'email', 'password', 'password_confirmation', 'userId', 'selectedRoles', 'roleSearch',
            'phone', 'staff_id', 'gender', 'date_of_birth', 'department', 'hire_date',
            'selectedBranchIds', 'defaultBranchId', 'membershipStatus',
            'roleAssignmentMode', 'branchRoles',
        ]);
        $this->membershipStatus = 'active';
        $this->roleAssignmentMode = 'shared';
    }

    public function openResetPassword($id)
    {
        $user = $this->managedUser((int) $id);
        $this->resetUserId = $id;
        $this->resetUserName = $user->name;
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
        $this->isResetOpen = true;
    }

    public function doResetPassword()
    {
        abort_if(!$this->canManageUsers(), 403);
        $this->validate([
            'newPassword'             => ['required', 'same:newPasswordConfirmation', Password::min(6)],
            'newPasswordConfirmation' => 'required',
        ], [
            'newPassword.same' => 'Passwords do not match.',
        ]);

        $this->managedUser((int) $this->resetUserId)->update([
            'password' => Hash::make($this->newPassword),
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => "Password for {$this->resetUserName} has been reset successfully."]);
        $this->closeResetModal();
    }

    public function closeResetModal()
    {
        $this->isResetOpen = false;
        $this->reset(['resetUserId', 'resetUserName', 'newPassword', 'newPasswordConfirmation']);
    }

    // ── CSV Import ──────────────────────────────────────────────────────────────

    public function openImport(): void
    {
        $this->importFile    = null;
        $this->importResults = [];
        $this->isImportOpen  = true;
    }

    public function closeImportModal(): void
    {
        $this->isImportOpen  = false;
        $this->importFile    = null;
        $this->importResults = [];
    }

    public function downloadTemplate()
    {
        $headers = [
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=staff_import_template.csv',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['name', 'email', 'password', 'role', 'phone', 'staff_id', 'gender', 'date_of_birth', 'department', 'hire_date']);
            fputcsv($f, ['Jane Doe', 'jane@clinic.com', 'ChangeMe123!', 'Cashier', '+233 50 123 4567', 'EMP-001', 'Female', '1990-05-15', 'Billing', '2024-01-01']);
            fclose($f);
        }, 200, $headers);
    }

    public function importCsv(): void
    {
        abort_if(!$this->canManageUsers(), 403);
        $this->validate(['importFile' => 'required|file|mimes:csv,txt|max:2048']);

        $path    = $this->importFile->getRealPath();
        $lines   = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $rows    = array_map('str_getcsv', $lines);
        $header  = array_shift($rows); // discard header row

        $validRoles = Role::pluck('name')->map(fn ($n) => strtolower($n))->flip()->toArray();
        $results    = ['created' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $row    = array_pad(array_map('trim', $row), 10, '');

            [$name, $email, $password, $roleInput, $phone, $staffId, $gender, $dob, $department, $hireDate] = $row;

            if ($name === '' && $email === '') {
                continue; // blank row
            }

            if ($name === '' || $email === '' || $password === '' || $roleInput === '') {
                $results['errors'][] = "Row {$rowNum}: name, email, password, and role are all required.";
                $results['skipped']++;
                continue;
            }

            if (mb_strlen($password) < 6) {
                $results['errors'][] = "Row {$rowNum}: password must be at least 6 characters.";
                $results['skipped']++;
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results['errors'][] = "Row {$rowNum}: \"{$email}\" is not a valid email address.";
                $results['skipped']++;
                continue;
            }

            if (User::where('email', $email)->exists()) {
                $results['errors'][] = "Row {$rowNum}: email \"{$email}\" already exists — skipped.";
                $results['skipped']++;
                continue;
            }

            $roleKey = strtolower($roleInput);
            if (!isset($validRoles[$roleKey])) {
                $results['errors'][] = "Row {$rowNum}: role \"{$roleInput}\" not found. Available: " . Role::pluck('name')->implode(', ') . '.';
                $results['skipped']++;
                continue;
            }

            $roleName = Role::whereRaw('LOWER(name) = ?', [$roleKey])->value('name');

            if ($staffId !== '' && DB::table('clinic_user')->where('clinic_id', $this->currentClinic()->id)->where('staff_identifier', $staffId)->exists()) {
                $results['errors'][] = "Row {$rowNum}: staff ID \"{$staffId}\" is already used by someone in this clinic — skipped.";
                $results['skipped']++;
                continue;
            }

            try {
                $user = User::create([
                    'name'          => $name,
                    'email'         => $email,
                    'password'      => Hash::make($password),
                    'phone'         => $phone ?: null,
                    'gender'        => in_array($gender, ['Male', 'Female', 'Other']) ? $gender : null,
                    'date_of_birth' => $dob ?: null,
                    'department'    => $department ?: null,
                    'hire_date'     => $hireDate ?: null,
                ]);
                $user->assignRole($roleName);
                $clinic = $this->currentClinic();
                $defaultBranch = $clinic->branches()->where('is_active', true)
                    ->orderByDesc('is_default')->orderBy('id')->firstOrFail();
                $clinic->users()->attach($user->id, [
                    'status' => 'active', 'is_default' => ! $user->clinics()->wherePivot('status', 'active')->exists(), 'staff_identifier' => $staffId ?: null,
                    'clinic_role' => $roleName, 'invited_by' => auth()->id(), 'joined_at' => now(),
                ]);
                $defaultBranch->users()->attach($user->id, [
                    'status' => 'active', 'is_default' => true, 'joined_at' => now(),
                ]);
                DB::table('branch_user_role')->insert([
                    'branch_id' => $defaultBranch->id,
                    'user_id' => $user->id,
                    'role_id' => Role::where('name', $roleName)->value('id'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $results['created']++;
            } catch (\Exception $e) {
                \Log::error("CSV import row {$rowNum} failed", ['email' => $email, 'error' => $e->getMessage()]);
                $results['errors'][] = "Row {$rowNum}: failed to create user — check server log for details.";
                $results['skipped']++;
            }
        }

        $this->importFile    = null;
        $this->importResults = $results;

        if ($results['created'] > 0) {
            $this->dispatch('notify', ...[
                'type'    => 'success',
                'message' => "{$results['created']} staff member(s) imported successfully." .
                             ($results['skipped'] > 0 ? " {$results['skipped']} row(s) skipped." : ''),
            ]);
        } else {
            $this->dispatch('notify', ...[
                'type'    => 'warning',
                'message' => 'No users were imported. Check the errors below.',
            ]);
        }
    }

    private function canManageUsers(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasRole('Super Admin') || $user?->can('manage users'));
    }

    private function validatedRolesByBranch(): ?array
    {
        $validRoles = Role::pluck('name')->all();
        $rolesByBranch = [];

        foreach (array_map('intval', $this->selectedBranchIds) as $branchId) {
            $roles = $this->roleAssignmentMode === 'shared'
                ? $this->selectedRoles
                : ($this->branchRoles[$branchId] ?? $this->branchRoles[(string) $branchId] ?? []);
            $roles = array_values(array_unique(array_intersect($roles, $validRoles)));
            if ($roles === []) {
                $field = $this->roleAssignmentMode === 'shared' ? 'selectedRoles' : "branchRoles.{$branchId}";
                $this->addError($field, 'Select at least one role for this branch.');
                return null;
            }
            $rolesByBranch[$branchId] = $roles;
        }

        return $rolesByBranch;
    }

    private function currentClinic(): Clinic
    {
        return app(TenantContext::class)->ensureFor(auth()->user())->requireClinic();
    }

    private function managedUser(int $id): User
    {
        return User::query()->whereKey($id)
            ->whereHas('clinics', fn ($query) => $query->whereKey($this->currentClinic()->id)->whereIn('clinic_user.status', self::CURRENT_MEMBER_STATUSES))
            ->firstOrFail();
    }
}
