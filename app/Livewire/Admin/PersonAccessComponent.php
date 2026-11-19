<?php

namespace App\Livewire\Admin;

use App\Models\AuditTrail;
use App\Models\User;
use App\Services\LicenseService;
use App\Services\OwnerAlerts;
use App\Support\Access\Access;
use App\Support\Access\Permissions;
use App\Support\Access\PersonalAccess;
use App\Support\Access\RoleMap;
use App\Support\Access\RoleNames;
use App\Support\Access\Screens;
use App\Support\ClinicNavigation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Users → Access (phase 4): one person's access in this clinic. Their role gives a set of
 * permissions; here single permissions can be switched on or off for them alone.
 * Owner: any switch. Manager: only permissions they hold themselves, never approvals.
 */
class PersonAccessComponent extends Component
{
    /** Never switchable per person: who may make Owners stays with the Owner role. */
    public const NOT_SWITCHABLE = ['staff.grant_owner'];

    public User $user;

    /** @var array<string, string> field(permission) => 'role' | 'on' | 'off' */
    public array $choices = [];

    /** Livewire reads dots as nesting, so permission keys travel with __ instead. */
    public static function field(string $permission): string
    {
        return str_replace('.', '__', $permission);
    }

    public static function permissionOf(string $field): string
    {
        return str_replace('__', '.', $field);
    }

    public function mount(User $user): void
    {
        abort_unless(Access::can('staff.manage'), 403);
        abort_unless(User::inCurrentClinic()->whereKey($user->id)->exists(), 404);
        $this->user = $user;
        $this->loadChoices();
    }

    private function loadChoices(): void
    {
        $overrides = PersonalAccess::overrides($this->user);
        $this->choices = collect(Permissions::keys())
            ->mapWithKeys(fn ($key) => [self::field($key) => array_key_exists($key, $overrides) ? ($overrides[$key] ? 'on' : 'off') : 'role'])
            ->all();
    }

    private function subject(): User
    {
        return PersonalAccess::asInThisBranch($this->user);
    }

    public function isOwnerAccount(): bool
    {
        return $this->subject()->getRoleNames()->contains(RoleMap::OWNER);
    }

    /** Why the signed-in person can't change this person's access at all, or null. */
    public function lockedReason(): ?string
    {
        if ($this->isOwnerAccount()) return 'The Owner has every permission; there is nothing to switch.';
        if ((int) $this->user->id === (int) Auth::id()) return 'You can\'t change your own access. Ask the Owner.';

        return null;
    }

    private function editorIsOwner(): bool
    {
        return Auth::user()?->getRoleNames()->contains(RoleMap::OWNER) ?? false;
    }

    /** May the signed-in person change this one switch? */
    public function mayChange(string $permission): bool
    {
        if ($this->lockedReason() || ! Permissions::exists($permission) || in_array($permission, self::NOT_SWITCHABLE, true)) return false;
        if ($this->editorIsOwner()) return true;

        return ! str_starts_with($permission, 'approve.') && Access::allows(Auth::user(), $permission);
    }

    public function save(OwnerAlerts $alerts): void
    {
        abort_unless(Access::can('staff.manage'), 403);
        abort_if($this->lockedReason() !== null, 403, $this->lockedReason() ?? '');

        $subject = $this->subject();
        $current = PersonalAccess::overrides($this->user);
        $before = [];
        $after = [];
        $raised = [];
        foreach ($this->choices as $field => $choice) {
            $permission = self::permissionOf((string) $field);
            if (! Permissions::exists($permission) || ! in_array($choice, ['role', 'on', 'off'], true)) continue;
            $wanted = ['role' => null, 'on' => true, 'off' => false][$choice];
            $had = $current[$permission] ?? null;
            if ($wanted === $had) continue;
            abort_unless($this->mayChange($permission), 403, 'You can\'t change "'.Permissions::label($permission).'" for this person.');

            $fromRole = PersonalAccess::fromRole($subject, $permission);
            $wasAllowed = $had ?? $fromRole;
            $nowAllowed = $wanted ?? $fromRole;
            PersonalAccess::set($this->user, $permission, $wanted, Auth::id());
            $before[$permission] = $had === null ? 'role' : ($had ? 'on' : 'off');
            $after[$permission] = $choice;
            if (! $wasAllowed && $nowAllowed) $raised[] = Permissions::label($permission);
        }

        if ($after === []) {
            $this->dispatch('notify', type: 'info', message: 'Nothing changed.');
            return;
        }

        AuditTrail::record('staff.access_changed', "Access changed for {$this->user->name}", $this->user, $before, $after, null, true);
        if ($raised) $alerts->accessIncreased($this->user, $raised);
        $this->loadChoices();
        $this->dispatch('notify', type: 'success', message: 'Access saved for '.$this->user->name.'.');
    }

    public function resetToRole(): void
    {
        foreach (array_keys($this->choices) as $field) {
            if ($this->mayChange(self::permissionOf($field))) $this->choices[$field] = 'role';
        }
    }

    /** Permissions whose screens all need plan features this clinic doesn't have. */
    private function planLocked(): array
    {
        $features = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $needs = Screens::permissionsFor($name);
            if (! $needs) continue;
            $gates = [];
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^feature(?:-or-leftover)?:(.+)$/', $middleware, $m)) {
                    $gates = array_merge($gates, explode(',', $m[1]));
                }
            }
            $open = $gates === [] || collect($gates)->contains(fn ($feature) => LicenseService::has($feature));
            foreach ($needs as $permission) {
                $features[$permission] = ($features[$permission] ?? false) || $open;
            }
        }

        return array_keys(array_filter($features, fn ($open) => ! $open));
    }

    /** What the person sees in the menu with their saved access: [group => [labels]]. */
    private function preview(User $subject): array
    {
        $me = Auth::user();
        $out = [];
        try {
            Auth::setUser($subject);
            foreach (['reception', 'doctor', 'admin'] as $menu) {
                foreach (ClinicNavigation::menu($menu) as $item) {
                    $group = isset($item['items']) ? $item['label'] : 'Main';
                    foreach ($item['items'] ?? [$item] as $link) {
                        if (! empty($link['label']) && ! in_array($link['label'], $out[$group] ?? [], true)) $out[$group][] = $link['label'];
                    }
                }
            }
        } finally {
            Auth::setUser($me);
        }

        return $out;
    }

    public function render()
    {
        $subject = $this->subject();
        $locked = $this->planLocked();
        $groups = [];
        foreach (Permissions::keys() as $key) {
            if (in_array($key, self::NOT_SWITCHABLE, true)) continue;
            $groups[Permissions::group($key)][$key] = [
                'label' => Permissions::label($key),
                'fromRole' => PersonalAccess::fromRole($subject, $key),
                'plan' => ! in_array($key, $locked, true),
                'editable' => $this->mayChange($key),
            ];
        }

        return view('livewire.admin.person-access-component', [
            'groups' => $groups,
            'roles' => RoleNames::labels($subject->getRoleNames()) ?: 'No role',
            'locked' => $this->lockedReason(),
            'switched' => count(PersonalAccess::overrides($this->user)),
            'preview' => $this->lockedReason() ? [] : $this->preview($subject),
        ])->layout('layouts.admin.admin-layout');
    }
}
