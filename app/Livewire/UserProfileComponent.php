<?php

namespace App\Livewire;

use App\Models\LoginLog;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Auth;

class UserProfileComponent extends Component
{
    use WithFileUploads;

    // Basic
    public string $name                  = '';
    public string $email                 = '';
    public string $password              = '';
    public string $password_confirmation = '';

    // Extended profile (editable by user)
    public string $phone         = '';
    public string $gender        = '';
    public string $date_of_birth = '';

    // Admin-managed (view-only for user)
    public string $staff_id   = '';
    public string $department = '';
    public string $hire_date  = '';

    // Avatar
    public $avatar = null; // temporary upload

    public string $activeTab = 'account';

    public function mount(): void
    {
        $user                 = Auth::user();
        $this->name           = $user->name;
        $this->email          = $user->email;
        $this->phone          = $user->phone          ?? '';
        $this->gender         = $user->gender         ?? '';
        $this->date_of_birth  = $user->date_of_birth  ? $user->date_of_birth->format('Y-m-d') : '';
        // Staff IDs belong to a clinic membership; show the one for the current clinic.
        $clinicId = app(\App\Support\Tenancy\TenantContext::class)->clinicId();
        $this->staff_id       = (string) ($clinicId ? \Illuminate\Support\Facades\DB::table('clinic_user')->where('user_id', $user->id)
            ->where('clinic_id', $clinicId)->value('staff_identifier') : '');
        $this->department     = $user->department     ?? '';
        $this->hire_date      = $user->hire_date      ? $user->hire_date->format('Y-m-d') : '';
    }

    protected function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'phone'         => 'nullable|string|max:25',
            'gender'        => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'nullable|date|before:today',
            'password'      => ['nullable', 'confirmed', Password::min(6)],
            'avatar'        => 'nullable|image|max:2048',
        ];
    }

    public function updateProfile(): void
    {
        $this->validate();

        $user         = Auth::user();
        $user->name   = $this->name;
        $user->phone  = $this->phone  ?: null;
        $user->gender = $this->gender ?: null;
        $user->date_of_birth = $this->date_of_birth ?: null;

        if ($this->password) {
            $user->password                   = Hash::make($this->password);
            $user->last_password_changed_at   = now();
        }

        if ($this->avatar) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $this->avatar->store('avatars', 'public');
            $this->avatar = null;
        }

        $user->save();
        $this->reset(['password', 'password_confirmation']);

        $this->dispatch('notify', ...[
            'type'    => 'success',
            'message' => 'Profile updated successfully!',
        ]);
    }

    public function removeAvatar(): void
    {
        $user = Auth::user();
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->avatar = null;
            $user->save();
        }
        $this->avatar = null;
        $this->dispatch('notify', ...['type' => 'info', 'message' => 'Avatar removed.']);
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    private function layoutForUser(): string
    {
        $user = Auth::user();

        return match(true) {
            $user->hasRole(['Super Admin', 'Manager']) => 'layouts.admin.admin-layout',
            $user->hasRole('Doctor')                   => 'layouts.doctor.doctor-layout',
            $user->hasRole('Secretary')                => 'layouts.secretary.secretary-layout',
            default                                    => 'layouts.secretary.secretary-layout',
        };
    }

    public function render()
    {
        $user        = Auth::user()->fresh();
        $allLoginLogs = LoginLog::where('user_id', $user->id)->orderByDesc('login_at')->get();
        $loginLogs    = $allLoginLogs->take(8);
        $totalLogins  = $allLoginLogs->count();
        $lastLogin    = $allLoginLogs->skip(1)->first();
        $accountAge  = $user->created_at->diffInDays(now());

        return view('livewire.user-profile-component', [
            'user'        => $user,
            'roles'       => $user->getRoleNames(),
            'loginLogs'   => $loginLogs,
            'totalLogins' => $totalLogins,
            'lastLogin'   => $lastLogin,
            'accountAge'  => $accountAge,
        ])->layout($this->layoutForUser());
    }
}
