<?php

namespace Tests\Feature;

use App\Livewire\Admin\AttendanceComponent;
use App\Models\AttendanceDepartment;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

/** A staff list brought in from a file: people added or updated by Employee ID, departments made, pay for the owner only. */
class AttendanceStaffImportTest extends TestCase
{
    use GivesClinicsAccess, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tenancy.enabled', true);
        $clinic = $this->activeClinic(['name' => 'Hospital A', 'slug' => 'hospital-a']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true]);
        foreach (['Super Admin' => 'admin', 'Manager' => 'manager'] as $roleName => $property) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user = User::factory()->create();
            $user->assignRole($role);
            $clinic->users()->attach($user, ['status' => 'active', 'clinic_role' => $roleName, 'is_default' => true]);
            DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'status' => 'active', 'is_default' => true]);
            DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);
            if ($property === 'manager') {
                $this->manager = $user;
            } else {
                $this->actingAs($user);
                app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
            }
        }
        AttendanceDepartment::create(['name' => 'Nursing']);
        AttendanceStaff::create(['employee_no' => '001', 'name' => 'A. Owusu', 'is_tracked' => true]);
    }

    private function file(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('staff.csv', $csv);
    }

    public function test_people_and_departments_come_in_from_a_file(): void
    {
        $csv = "\xEF\xBB\xBFEmployee ID,Name,Department,Pay type,Pay rate,Start date\r\n"
            ."=\"001\",Ama Owusu,nursing,Monthly salary,\"2,200\",05/01/2026\r\n"
            ."002,Kofi Boateng,Laboratory,hourly,20,\r\n"
            .",Nobody,Laboratory,,,\r\n"
            ."004,,Pharmacy,,,\r\n"
            ."005,Yaa Mensah,,,,next week\r\n"
            ."\r\n";
        $page = Livewire::test(AttendanceComponent::class)->assertSee('Bring in a staff list')->call('openStaffImport')->assertSee('Download a template')
            ->call('importStaff')->assertHasErrors(['staffFile'])
            ->set('staffFile', $this->file($csv))->call('importStaff')->assertHasNoErrors()
            ->assertSee('Row 4: no Employee ID')->assertSee('Row 5: no name')->assertSee('Row 6: the start date');
        $this->assertSame(['added' => 2, 'updated' => 1, 'departments' => 1], array_diff_key($page->get('staffImported'), ['skipped' => 1]));

        // 001 was known: renamed and placed, not added twice; "nursing" matched the department already here.
        $ama = AttendanceStaff::where('employee_no', '001')->sole();
        $this->assertSame(['Ama Owusu', 'Nursing', 'monthly', '2200.00', '2026-01-05'], [$ama->name, $ama->unit->name, $ama->pay_type, $ama->pay_rate, $ama->starts_on->format('Y-m-d')]);
        $kofi = AttendanceStaff::where('employee_no', '002')->sole();
        $this->assertSame(['Laboratory', 'hourly', true], [$kofi->unit->name, $kofi->pay_type, $kofi->is_tracked]);
        $this->assertSame(['Laboratory', 'Nursing'], AttendanceDepartment::orderBy('name')->pluck('name')->all());
        $this->assertNull(AttendanceStaff::where('employee_no', '005')->sole()->starts_on);
        $this->assertSame(3, AttendanceStaff::count());

        // Headings in another order, semicolons, and a file with no headings.
        $page->set('staffFile', $this->file("Name;Ward;Staff No\nEsi Darko;Pharmacy;006\n"))->call('importStaff')->assertHasNoErrors();
        $this->assertSame('Pharmacy', AttendanceStaff::where('employee_no', '006')->sole()->unit->name);
        $page->set('staffFile', $this->file("007,Someone\n"))->call('importStaff')->assertHasErrors(['staffFile']);
        $page->set('staffFile', UploadedFile::fake()->create('staff.pdf', 10))->call('importStaff')->assertHasErrors(['staffFile']);
    }

    public function test_a_manager_brings_people_in_without_pay(): void
    {
        $context = app(TenantContext::class);
        $this->flushSession();
        $this->actingAs($this->manager);
        $context->set($this->manager, $context->clinic(), $context->branch(), [$context->branchId()]);

        Livewire::test(AttendanceComponent::class)->call('openStaffImport')
            ->set('staffFile', $this->file("Employee ID,Name,Pay type,Pay rate\n002,Kofi Boateng,hourly,20\n"))->call('importStaff')->assertHasNoErrors();
        $kofi = AttendanceStaff::where('employee_no', '002')->sole();
        $this->assertSame(['Kofi Boateng', null, null], [$kofi->name, $kofi->pay_type, $kofi->pay_rate]);
    }
}
