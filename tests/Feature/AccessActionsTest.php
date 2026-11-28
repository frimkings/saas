<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access\Access;
use App\Support\Access\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Actions inside pages ask for permissions too (phase 1B part 2). This spells out who may do the
 * sensitive actions, and stops new role-name checks from creeping back in.
 */
class AccessActionsTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['Super Admin', 'Manager', 'Secretary', 'Cashier', 'Doctor', 'Optician', 'Optical Assistant', 'Lab Technician', 'Staff'];

    /** Action => roles that may do it (Super Admin = the clinic owner). */
    private const WHO = [
        // Owner only
        'records.delete' => ['Super Admin'],
        'stock.write_off' => ['Super Admin'],
        'cashbook.correct' => ['Super Admin'],
        'approve.password_resets' => ['Super Admin'],
        'approve.corrections' => ['Super Admin'],
        'approve.spectacle_renewals' => ['Super Admin'],
        'attendance.payroll' => ['Super Admin'],
        'attendance.emails' => ['Super Admin'],
        'staff.grant_owner' => ['Super Admin'],
        'billing.manage' => ['Super Admin'],
        'settings.manage' => ['Super Admin'],
        'system.manage' => ['Super Admin'],
        'messages.patients' => ['Super Admin'],
        'reports.sales' => ['Super Admin'],
        'reports.finance' => ['Super Admin'],
        // Owner and Manager
        'approve.discounts' => ['Super Admin', 'Manager'],
        'approve.refunds' => ['Super Admin', 'Manager'],
        'sales.refund_any' => ['Super Admin', 'Manager'],
        'pos.all_open_sales' => ['Super Admin', 'Manager'],
        'optical.manage' => ['Super Admin', 'Manager'],
        'expenses.manage' => ['Super Admin', 'Manager'],
        'scans.manage' => ['Super Admin', 'Manager'],
        'spectacles.override' => ['Super Admin', 'Manager'],
        'attendance.manage' => ['Super Admin', 'Manager'],
        'dashboard.view' => ['Super Admin', 'Manager'],
        // Desk and till
        'receipts.view_all' => ['Super Admin', 'Manager', 'Secretary'],
        'sales.refund_own' => ['Super Admin', 'Cashier'],
        'dayclose.manage' => ['Super Admin', 'Manager', 'Cashier'],
        'pos.sell' => ['Super Admin', 'Manager', 'Secretary', 'Cashier'],
        // Clinical
        'clinical.records' => ['Super Admin', 'Doctor'],
    ];

    /**
     * Role names that remain on purpose: they ask who someone is, not what they may do.
     * file => how many role-name checks it may keep.
     */
    private const IDENTITY_CHECKS = [
        'app/Providers/AppServiceProvider.php' => 1,          // the owner passes every check
        'app/Providers/AuthServiceProvider.php' => 1,         // same, for policies
        'app/Services/Platform/Announcements.php' => 1,       // platform news goes to clinic owners
        'app/Services/OwnerAlerts.php' => 1,                  // the owner isn't alerted about a reset link they sent
        'app/Livewire/Admin/UserRoleManagerComponent.php' => 1, // is the person being edited an owner here?
        'app/Livewire/Doctor/PatientRecordsComponent.php' => 1, // a doctor opening a record needs its patient
        'app/Support/Tenancy/RoleDashboardResolver.php' => 1, // landing page by role
        'app/Http/Middleware/Secretary.php' => 1,             // legacy middleware, not registered
    ];

    public function test_each_role_gets_exactly_the_sensitive_actions_decided(): void
    {
        $problems = [];
        foreach (self::ROLES as $role) {
            $user = User::factory()->create();
            $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
            $user = $user->fresh();
            foreach (self::WHO as $permission => $roles) {
                $this->assertTrue(Permissions::exists($permission), "{$permission} is not in the catalogue");
                $expected = in_array($role, $roles, true);
                if (Access::allows($user, $permission) !== $expected) {
                    $problems[] = "{$role} ".($expected ? 'should' : 'should not')." have {$permission}";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_a_cashier_may_refund_only_their_own_sales(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole(Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']));
        $cashier = $cashier->fresh();

        $this->assertTrue(Access::allows($cashier, 'sales.refund_own'));
        $this->assertFalse(Access::allows($cashier, 'sales.refund_any'));
    }

    public function test_no_new_role_name_checks_outside_the_identity_list(): void
    {
        $found = [];
        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                $relative = substr($path, strlen(str_replace('\\', '/', base_path())) + 1);
                if (str_contains($relative, 'RolePermissionManager') || str_starts_with($relative, 'app/Support/Access/')) {
                    continue;
                }
                $count = preg_match_all("/hasRole\(|hasAnyRole\(|@role\(|@hasrole|@hasanyrole/", file_get_contents($path));
                if ($count > 0) {
                    $found[$relative] = $count;
                }
            }
        }
        ksort($found);
        $allowed = self::IDENTITY_CHECKS;
        ksort($allowed);

        $this->assertSame($allowed, $found, 'Use App\Support\Access\Access (or @access) instead of role names.');
    }
}
