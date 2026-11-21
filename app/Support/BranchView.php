<?php

namespace App\Support;

use App\Livewire\Admin\BranchesOverviewComponent;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which branches a money page covers (21 Nov 2026): '' the branch that is open (the default for everyone),
 * 'all' every branch the owner can open, or one branch's id. Only the owner of a clinic with more than one
 * branch can pick; anyone else always gets the open branch.
 */
class BranchView
{
    /** Whether the person may pick (the owner, more than one branch they can open). */
    public static function canPick(): bool
    {
        return BranchesOverviewComponent::allowed();
    }

    /** @return array<int, string> id => name, for the picker */
    public static function options(): array
    {
        $ids = BranchesOverviewComponent::branchIds();

        return Branch::withoutGlobalScopes()->whereIn('id', $ids)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** The branch ids to cover, or null for "the open branch" (the models' own branch scope applies). */
    public static function ids(string|int|null $view): ?array
    {
        $view = (string) $view;
        if ($view === '' || ! self::canPick()) return null;
        $ids = BranchesOverviewComponent::branchIds();
        if ($view === 'all') return $ids;

        return in_array((int) $view, $ids, true) ? [(int) $view] : null;
    }

    /** Narrow a query to the chosen branches (or leave the open-branch scope in place). */
    public static function apply(Builder $query, string|int|null $view, ?string $column = null): Builder
    {
        $ids = self::ids($view);
        if ($ids === null) return $query;

        return $query->withoutGlobalScope('branch')->whereIn($column ?? $query->getModel()->qualifyColumn('branch_id'), $ids);
    }

    /** "All branches", a branch's name, or null for the open branch: for page titles and exports. */
    public static function label(string|int|null $view): ?string
    {
        $ids = self::ids($view);
        if ($ids === null) return null;

        return (string) $view === 'all' ? 'All branches' : (self::options()[$ids[0]] ?? null);
    }
}
