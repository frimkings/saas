<?php

namespace Tests\Feature;

use App\Models\{Clinic, PlatformCreditNote, PlatformInvoice, SubscriptionApprovalRequest, User};
use App\Services\SubscriptionApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionApprovalControlsTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): PlatformInvoice
    {
        $clinic = Clinic::create(['name' => 'Controlled Clinic', 'slug' => 'controlled-'.uniqid()]);

        return PlatformInvoice::create([
            'number' => 'INV-CONTROL-'.uniqid(), 'clinic_id' => $clinic->id,
            'period_start' => now(), 'period_end' => now()->addMonth(), 'due_date' => now()->addWeek(),
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'amount_paid' => 0,
            'currency' => 'GHS', 'status' => 'unpaid',
        ]);
    }

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email]);
    }

    public function test_requester_cannot_approve_but_a_second_admin_executes_once(): void
    {
        $invoice = $this->invoice();
        $maker = $this->user('maker@example.test');
        $checker = $this->user('checker@example.test');
        $service = app(SubscriptionApprovalService::class);
        $request = $service->request('credit_note', PlatformInvoice::class, $invoice->id, $invoice->clinic_id, ['amount' => 25.0], 'Approved customer adjustment', $maker->id);

        try {
            $service->approve($request, $maker->id, 'Self approval attempt');
            $this->fail('A requester was allowed to approve their own action.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('platform_credit_notes', 0);
        }

        $service->approve($request, $checker->id, 'Verified against the account record');
        $this->assertDatabaseHas('subscription_approval_requests', ['id' => $request->id, 'status' => 'approved', 'reviewed_by' => $checker->id]);
        $this->assertDatabaseHas('platform_credit_notes', ['platform_invoice_id' => $invoice->id, 'amount' => 25]);
        $this->expectException(ValidationException::class);
        $service->approve($request->refresh(), $checker->id, 'Duplicate execution attempt');
    }

    public function test_modified_payload_is_rejected_without_execution(): void
    {
        $invoice = $this->invoice();
        $maker = $this->user('maker2@example.test');
        $checker = $this->user('checker2@example.test');
        $service = app(SubscriptionApprovalService::class);
        $request = $service->request('credit_note', PlatformInvoice::class, $invoice->id, $invoice->clinic_id, ['amount' => 10.0], 'Original authorized value', $maker->id);
        $request->update(['payload' => ['amount' => 90.0]]);

        try {
            $service->approve($request->refresh(), $checker->id, 'Payload reviewed');
            $this->fail('A modified approval payload was executed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(0, PlatformCreditNote::count());
    }

    public function test_expired_request_is_marked_and_cannot_execute(): void
    {
        $invoice = $this->invoice();
        $maker = $this->user('maker3@example.test');
        $checker = $this->user('checker3@example.test');
        $service = app(SubscriptionApprovalService::class);
        $request = $service->request('invoice_void', PlatformInvoice::class, $invoice->id, $invoice->clinic_id, [], 'Duplicate billing document', $maker->id);
        $request->update(['expires_at' => now()->subMinute()]);

        try {
            $service->approve($request->refresh(), $checker->id, 'Reviewed too late');
            $this->fail('An expired request was executed.');
        } catch (ValidationException) {
            $this->assertSame('expired', $request->refresh()->status);
        }
        $this->assertNull($invoice->refresh()->voided_at);
    }
}
