<?php

namespace Tests\Feature;

use App\Livewire\Admin\PaymentMethodsComponent;
use App\Livewire\Cashier\CashierPatientClearanceComponent;
use App\Livewire\OutstandingBalancesComponent;
use App\Livewire\POSComponent;
use App\Models\Category;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Sales;
use App\Models\User;
use App\Support\PaymentMethods;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $admin;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startOfflineTrial();
        foreach (['Super Admin', 'Cashier', 'Manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(['Super Admin', 'Cashier']);
        $this->actingAs($this->admin);
        $this->patient = Patient::factory()->create(['user_id' => $this->admin->id]);
    }

    public function test_each_line_starts_with_the_built_in_list(): void
    {
        $this->assertSame(['cash', 'momo', 'card', 'cheque', 'code'], PaymentMethods::keys(PaymentMethods::CLINIC));
        $this->assertSame(['cash', 'momo', 'card', 'bank_transfer'], PaymentMethods::keys(PaymentMethods::OPTICAL));
        $this->assertSame('Mobile Money', PaymentMethods::label('momo'));
    }

    public function test_clinic_can_switch_off_rename_and_add_methods(): void
    {
        $this->clinicMethods()
            ->set('methods.3.is_active', false)          // Cheque off
            ->set('methods.1.label', 'MTN MoMo')
            ->set('newLabel', 'Zeepay')
            ->call('add')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['cash' => 'Cash', 'momo' => 'MTN MoMo', 'card' => 'Card', 'code' => 'Hubtel Wallet', 'custom_zeepay' => 'Zeepay'],
            PaymentMethods::active(PaymentMethods::CLINIC)
        );
        // The optical list is separate and unchanged.
        $this->assertSame(['cash', 'momo', 'card', 'bank_transfer'], PaymentMethods::keys(PaymentMethods::OPTICAL));
        // A switched-off method keeps its name on old payments.
        $this->assertSame('Cheque', PaymentMethods::label('cheque'));
    }

    public function test_at_least_one_method_stays_on_and_names_are_unique(): void
    {
        $component = $this->clinicMethods();
        foreach (array_keys(PaymentMethods::active(PaymentMethods::CLINIC)) as $i => $key) {
            $component->set("methods.{$i}.is_active", false);
        }
        $component->call('save')->assertHasErrors('methods');

        $this->clinicMethods()->set('newLabel', 'cash')->call('add')->assertHasErrors('newLabel');
        $this->assertSame(0, PaymentMethod::count());
    }

    public function test_a_method_used_on_a_payment_can_only_be_switched_off(): void
    {
        $this->clinicMethods()->set('newLabel', 'Zeepay')->call('add')->call('save');
        $sale = $this->sale(100, 0, 'partial');
        PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => 10, 'payment_method' => 'custom_zeepay', 'collected_by' => $this->admin->id]);

        $component = $this->clinicMethods();
        $index = collect($component->get('methods'))->search(fn ($m) => $m['key'] === 'custom_zeepay');
        $component->call('remove', $index)->call('save');

        $this->assertArrayHasKey('custom_zeepay', PaymentMethods::active(PaymentMethods::CLINIC));
    }

    public function test_tills_take_only_the_clinics_methods(): void
    {
        $this->clinicMethods()->set('methods.0.is_active', false)->set('newLabel', 'Zeepay')->call('add')->call('save'); // Cash off

        // Clearance
        $category = Category::factory()->create(['user_id' => $this->admin->id, 'name' => 'Clinical Services ' . uniqid(), 'type' => 'service']);
        $service = Product::factory()->create(['user_id' => $this->admin->id, 'category_id' => $category->id, 'selling_price' => 100, 'cost_price' => 10]);
        $clear = fn (string $method) => Livewire::test(CashierPatientClearanceComponent::class)
            ->set('patientClearanceId', $this->patient->id)->set('patientName', $this->patient->name)
            ->call('createClearance', (string) $service->id, json_encode([['method' => $method, 'amount' => 100]]));
        $clear('cash')->assertHasErrors('selectedServiceId');
        $clear('custom_zeepay')->assertHasNoErrors();
        $this->assertSame('custom_zeepay', PaymentTransaction::latest('id')->value('payment_method'));

        // POS starts on the first method switched on and refuses others
        Livewire::test(POSComponent::class)
            ->assertSet('newPaymentMethod', 'momo')
            ->call('selectNewPaymentMethod', 'cash')
            ->assertSet('newPaymentMethod', 'momo');

        // Outstanding balances
        $sale = $this->sale(100, 40, 'partial');
        $collect = fn (string $method) => Livewire::test(OutstandingBalancesComponent::class)
            ->set('selectedSaleId', $sale->id)->set('collectAmount', 10)->set('paymentMethod', $method)->call('collectPayment');
        $collect('cash')->assertHasErrors('paymentMethod');
        $collect('custom_zeepay')->assertHasNoErrors();
    }

    public function test_optical_methods_are_set_on_their_own(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(PaymentMethodsComponent::class, ['optical' => true])
            ->set('methods.3.is_active', false) // Bank transfer off
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(PaymentMethods::isActive(PaymentMethods::OPTICAL, 'bank_transfer'));
        $this->assertTrue(PaymentMethods::isActive(PaymentMethods::CLINIC, 'cheque'));
    }

    public function test_only_super_admins_edit_clinic_methods_and_managers_may_edit_optical(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $this->actingAs($manager);

        Livewire::test(PaymentMethodsComponent::class)->assertForbidden();
        Livewire::test(PaymentMethodsComponent::class, ['optical' => true])->assertOk();
    }

    private function clinicMethods()
    {
        return Livewire::test(PaymentMethodsComponent::class);
    }

    private function sale(float $total, float $paid, string $status): Sales
    {
        return Sales::create(['user_id' => $this->admin->id, 'patient_id' => $this->patient->id, 'business_line' => 'clinic',
            'transaction_id' => 'PM-' . uniqid(), 'total_amount' => $total, 'amount_paid' => $paid, 'payment_status' => $status]);
    }
}
