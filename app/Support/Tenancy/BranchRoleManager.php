<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class BranchRoleManager
{
    public function roles(User $user, Branch|int $branch): Collection
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;
        return Role::query()->whereIn('id', function ($query) use ($user, $branchId) {
            $query->select('role_id')->from('branch_user_role')
                ->where('user_id', $user->id)->where('branch_id', $branchId);
        })->get();
    }

    public function hydrate(User $user, Branch $branch): User
    {
        return $user->setRelation('roles', $this->roles($user, $branch));
    }
}
