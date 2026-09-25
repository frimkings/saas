<?php

namespace Tests\Feature;

use App\Livewire\OutstandingBalancesComponent;
use App\Models\Patient;
use App\Models\PaymentTransaction;
use App\Models\Sales;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    private User $cashier;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('Cashier');
        $this->patient = Patient::factory()->create(['user_id' => $this->cashier->id]);

        $this->actingAs($this->cashier);
    }

    public function test_duplicate_balance_payment_key_returns_existing_payment(): void
    {
        $sale = $this->makeSale([
            'total_amount' => 100,
            'amount_paid' => 50,
            'payment_status' => 'partial',
        ]);
        $key = (string) Str::uuid();

        PaymentTransaction::create([
            'sale_id' => $sale->id,
            'idempotency_key' => $key,
            'amount' => 50,
            'payment_method' => 'cash',
            'collected_by' => $this->cashier->id,
        ]);

        Livewire::test(OutstandingBalancesComponent::class)
            ->set('selectedSaleId', $sale->id)
            ->set('collectAmount', 50)
            ->set('paymentMethod', 'cash')
            ->set('paymentIdempotencyKey', $key)
            ->call('collectPayment')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $this->assertSame(1, PaymentTransaction::where('sale_id', $sale->id)->count());
        $this->assertSame('50.00', $sale->fresh()->amount_paid);
    }

    public function test_sales_idempotency_key_is_unique(): void
    {
        $key = (string) Str::uuid();
        $this->makeSale(['idempotency_key' => $key]);

        $this->expectException(QueryException::class);

        $this->makeSale(['idempotency_key' => $key]);
    }

    private function makeSale(array $overrides = []): Sales
    {
        return Sales::create(array_merge([
            'user_id' => $this->cashier->id,
            'patient_id' => $this->patient->id,
            'transaction_id' => 'TXN-' . Str::upper(Str::random(12)),
            'total_amount' => 100,
            'amount_paid' => 100,
            'payment_status' => 'paid',
        ], $overrides));
    }
}
