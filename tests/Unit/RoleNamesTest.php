<?php

namespace Tests\Unit;

use App\Support\Access\RoleMap;
use App\Support\Access\RoleNames;
use PHPUnit\Framework\TestCase;

/** Phase 2: clinics see "Owner", while the stored role keeps its old name. */
class RoleNamesTest extends TestCase
{
    public function test_clinics_see_owner_and_a_clear_staff_label(): void
    {
        $this->assertSame('Super Admin', RoleMap::OWNER, 'The stored name must not change: imports and old records use it.');
        $this->assertSame('Owner', RoleNames::label('Super Admin'));
        $this->assertSame('Staff (attendance only)', RoleNames::label('Staff'));
        $this->assertSame('Manager', RoleNames::label('Manager'));
        $this->assertSame('Owner, Cashier', RoleNames::labels(['Super Admin', 'Cashier']));
    }

    public function test_roles_list_owner_then_manager_then_alphabetical(): void
    {
        $names = ['Secretary', 'Super Admin', 'Cashier', 'Manager', 'Doctor', 'Staff'];
        usort($names, fn ($a, $b) => strcmp(RoleNames::sortKey($a), RoleNames::sortKey($b)));

        $this->assertSame(['Super Admin', 'Manager', 'Cashier', 'Doctor', 'Secretary', 'Staff'], $names);
    }
}
