<?php

namespace Tests\Feature;

use App\Livewire\Cashier\CashierPatientClearanceComponent;
use App\Models\Category;
use App\Models\CashierPatientClearance;
use App\Models\Patient;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Sales;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class CashierPatientClearanceRenderingTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    public function test_component_renders_with_a_single_root_element(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Cashier', 'web'));

        $this->actingAs($user);

        Livewire::test(CashierPatientClearanceComponent::class)
            ->assertStatus(200)
            ->assertSee('Patient Clearance');
    }

    public function test_paid_clearance_records_an_exact_payment_and_sale(): void
    {
        [$user, $patient, $service] = $this->clearanceFixtures();

        Livewire::actingAs($user)
            ->test(CashierPatientClearanceComponent::class)
            ->set('patientClearanceId', $patient->id)
            ->set('patientName', $patient->name)
            ->call('createClearance', (string) $service->id, json_encode([
                ['method' => 'cash', 'amount' => 250],
                ['method' => 'card', 'amount' => 150],
            ]))
            ->assertHasNoErrors();

        $clearance = CashierPatientClearance::where('patient_id', $patient->id)->firstOrFail();
        $sale = Sales::findOrFail($clearance->sale_id);

        $this->assertSame('Paid', $clearance->payment_status);
        $this->assertSame(400.0, (float) $sale->amount_paid);
        $this->assertSame(2, PaymentTransaction::where('sale_id', $sale->id)->count());
    }

    public function test_paid_clearance_rejects_incomplete_payment_without_writing_records(): void
    {
        [$user, $patient, $service] = $this->clearanceFixtures();

        Livewire::actingAs($user)
            ->test(CashierPatientClearanceComponent::class)
            ->set('patientClearanceId', $patient->id)
            ->call('createClearance', (string) $service->id, json_encode([
                ['method' => 'cash', 'amount' => 399],
            ]))
            ->assertHasErrors('selectedServiceId');

        $this->assertFalse(CashierPatientClearance::where('patient_id', $patient->id)->exists());
        $this->assertFalse(Sales::where('patient_id', $patient->id)->exists());
    }

    public function test_unpaid_clearance_does_not_create_a_sale(): void
    {
        [$user, $patient] = $this->clearanceFixtures();

        Livewire::actingAs($user)
            ->test(CashierPatientClearanceComponent::class)
            ->set('patientClearanceId', $patient->id)
            ->set('patientName', $patient->name)
            ->call('createClearance', 'unpaid', '[]')
            ->assertHasNoErrors();

        $clearance = CashierPatientClearance::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame('Unpaid', $clearance->payment_status);
        $this->assertNull($clearance->sale_id);
    }

    private function clearanceFixtures(): array
    {
        $this->startOfflineTrial();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Cashier', 'web'));
        $patient = Patient::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create([
            'user_id' => $user->id,
            'name' => 'Clinical Services ' . uniqid(),
            'type' => 'service',
        ]);
        $service = Product::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'selling_price' => 400,
            'cost_price' => 100,
        ]);

        return [$user, $patient, $service];
    }
}
