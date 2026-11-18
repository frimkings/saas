<?php

namespace Tests\Feature;

use App\Models\{Patient, User};
use App\Services\{SubscriptionQuotaService, SubscriptionService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Each plan's users, scan storage and branches: up to the limit is fine, one more is refused (18 Nov 2026). */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ClinicOnPlan;

    /** family => [users (null = unlimited), storage MB] as sold on the pricing page. */
    public static function plans(): array
    {
        return [
            'clinic-starter' => ['clinic-starter', 2, 20],
            'clinic-standard' => ['clinic-standard', 4, 150],
            'clinic-plus' => ['clinic-plus', 10, 300],
            'clinic-pro' => ['clinic-pro', null, 2048],
        ];
    }

    private function refused(callable $check): bool
    {
        try {
            $check();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('subscription', $e->errors());

            return true;
        }

        return false;
    }

    private function addStaff(int $count): void
    {
        foreach (range(1, $count) as $n) {
            $this->clinic->users()->attach(User::factory()->create()->id, ['status' => 'active', 'is_default' => false]);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_users_up_to_the_plans_number(string $family, ?int $users, int $storage): void
    {
        $this->ownerOn($family);
        $quota = app(SubscriptionQuotaService::class);

        if ($users === null) {
            $this->addStaff(29);
            $this->assertFalse($this->refused(fn () => $quota->assertUserAvailable($this->clinic)), "$family: unlimited users, 30 is fine");

            return;
        }
        // The owner is the first user.
        if ($users > 2) $this->addStaff($users - 2);
        $this->assertFalse($this->refused(fn () => $quota->assertUserAvailable($this->clinic)), "$family: user $users of $users is allowed");
        $this->addStaff(1);
        $this->assertTrue($this->refused(fn () => $quota->assertUserAvailable($this->clinic)), "$family: user ".($users + 1).' is refused');
        // A deactivated member frees the place.
        $this->clinic->users()->updateExistingPivot($this->clinic->users()->latest('users.id')->first()->id, ['status' => 'inactive']);
        $this->assertFalse($this->refused(fn () => $quota->assertUserAvailable($this->clinic)), "$family: a deactivated member frees a place");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_scan_storage_up_to_the_plans_allowance(string $family, ?int $users, int $storage): void
    {
        $owner = $this->ownerOn($family);
        $quota = app(SubscriptionQuotaService::class);
        $this->assertSame($storage, $quota->storageLimitMb($this->clinic));

        $patient = Patient::createWithGeneratedPxNumber(['user_id' => $owner->id, 'name' => 'Yaw Boateng', 'contact' => '0241113333', 'gender' => 'Male']);
        DB::table('patient_documents')->insert(['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $patient->id, 'document_type' => 'oct',
            'title' => 'Earlier scans', 'file_path' => 'documents/earlier.pdf', 'original_name' => 'earlier.pdf', 'file_size' => ($storage - 1) * 1048576, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertFalse($this->refused(fn () => $quota->assertStorageAvailable($this->clinic, 1048576)), "$family: the last MB fits");
        $this->assertTrue($this->refused(fn () => $quota->assertStorageAvailable($this->clinic, 1048576 + 1)), "$family: past $storage MB is refused");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_one_branch_is_included(string $family, ?int $users, int $storage): void
    {
        $this->ownerOn($family);
        $service = app(SubscriptionService::class);

        $allowance = $service->branchAllowance($this->clinic);
        $this->assertSame([1, false], [$allowance['limit'], $allowance['can_add']], "$family: the main branch fills the plan's one");
    }
}
