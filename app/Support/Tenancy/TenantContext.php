<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Collection;
use LogicException;

class TenantContext
{
    private ?User $user = null;
    private ?Clinic $clinic = null;
    private ?Branch $branch = null;
    private array $authorizedBranchIds = [];
    /** The user's active clinics and this clinic's active branches, when already loaded. */
    private ?Collection $availableClinics = null;
    private ?Collection $availableBranches = null;

    public function set(User $user, Clinic $clinic, Branch $branch, array $authorizedBranchIds): void
    {
        if ((int) $branch->clinic_id !== (int) $clinic->id) {
            throw new LogicException('The selected branch does not belong to the selected clinic.');
        }

        if (! in_array((int) $branch->id, array_map('intval', $authorizedBranchIds), true)) {
            throw new LogicException('The selected branch is not authorized for this user.');
        }

        $this->user = $user;
        $this->clinic = $clinic;
        $this->branch = $branch;
        $this->authorizedBranchIds = array_values(array_unique(array_map('intval', $authorizedBranchIds)));
        $this->availableClinics = null;
        $this->availableBranches = null;
    }

    /** Keeps the membership lists the middleware already loaded, for the clinic/branch switcher. */
    public function setAvailable(Collection $clinics, Collection $branches): void
    {
        $this->availableClinics = $clinics;
        $this->availableBranches = $branches;
    }

    /** The signed-in user's active clinics. */
    public function availableClinics(): Collection
    {
        $user = $this->user ?? auth()->user();

        return $this->availableClinics ??= $user
            ? $user->clinics()->where('clinics.status', 'active')->wherePivot('status', 'active')->get()
            : collect();
    }

    /** The user's active branches in the current clinic, by name. */
    public function availableBranches(): Collection
    {
        return $this->availableBranches ??= $this->user && $this->clinic
            ? $this->user->branches()->where('branches.clinic_id', $this->clinic->id)
                ->where('branches.is_active', true)->wherePivot('status', 'active')->orderBy('branches.name')->get()
            : collect();
    }

    public function clear(): void
    {
        $this->user = null;
        $this->clinic = null;
        $this->branch = null;
        $this->authorizedBranchIds = [];
        $this->availableClinics = null;
        $this->availableBranches = null;
    }

    public function resolved(): bool
    {
        return $this->clinic !== null && $this->branch !== null;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function clinic(): ?Clinic
    {
        return $this->clinic;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function clinicId(): ?int
    {
        return $this->clinic?->id;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function authorizedBranchIds(): array
    {
        return $this->authorizedBranchIds;
    }

    public function requireClinic(): Clinic
    {
        return $this->clinic ?? throw new LogicException('No clinic has been resolved for this request.');
    }

    public function requireBranch(): Branch
    {
        return $this->branch ?? throw new LogicException('No branch has been resolved for this request.');
    }

    /** Resolve the bootstrapped tenant for administration during staged cutover. */
    public function ensureFor(User $user): self
    {
        if ($this->resolved()) {
            return $this;
        }
        if (config('tenancy.enabled')) {
            return $this->resolveFor($user);
        }

        $hasMembership = $user->clinics()->where('clinics.status', 'active')
            ->wherePivot('status', 'active')->exists();
        if ($hasMembership) {
            return $this->resolveFor($user);
        }

        // Compatibility mode predates memberships for newly constructed test or
        // maintenance users. It may read the bootstrap tenant but never does so
        // after enforcement is enabled.
        $clinic = Clinic::query()->where('status', 'active')->orderBy('id')->firstOrFail();
        $branch = $clinic->branches()->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('id')->firstOrFail();
        $this->set($user, $clinic, $branch, [$branch->id]);
        return $this;
    }

    /** Resolve context from active server-side memberships, never request input. */
    public function resolveFor(User $user): self
    {
        $clinic = app(ClinicMembershipManager::class)->ensureDefault($user);
        if (! $clinic) {
            throw new LogicException('The user has no active clinic membership.');
        }
        $branches = $user->branches()->where('branches.clinic_id', $clinic->id)
            ->where('branches.is_active', true)->wherePivot('status', 'active');
        $ids = (clone $branches)->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        $branch = $branches->orderByDesc('branch_user.is_default')
            ->orderByDesc('branches.is_default')->orderBy('branches.id')->first();
        if (! $branch) {
            throw new LogicException('The user has no active branch membership.');
        }
        $this->set($user, $clinic, $branch, $ids);
        return $this;
    }
}
