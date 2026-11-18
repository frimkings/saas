<?php

namespace Tests\Feature;

use App\Livewire\Optical\IncomingRequestsComponent;
use App\Livewire\Optical\OpticalOrderCreateComponent;
use App\Livewire\Optical\PartnerClinicsComponent;
use App\Livewire\Secretary\OpticalCentresComponent;
use App\Livewire\Secretary\OpticalOwedComponent;
use App\Livewire\Secretary\SendToOpticalComponent;
use App\Livewire\Secretary\SentToOpticalComponent;
use App\Models\{Branch, CashierPatientClearance, Clinic, ClinicSubscription, Consultations, Expense, LensOrder, OpticalLinkInvite, OpticalLinkPayment, OpticalOrderRequest, OpticalPartnerClinic, OpticalPartnerPayment, Patient, Refractions, SubscriptionPlan, User};
use App\Services\OpticalLinks\OpticalLinkPaymentService;
use App\Services\OpticalLinks\OpticalLinkService;
use App\Services\OpticalOrderWorkflowService;
use App\Services\OpticalPartnerAccountService;
use App\Support\Tenancy\BranchRoleManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Linked optical centres: invite, accept, send a spectacle order, accept it into a partner job, follow it. */
class OpticalLinksTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: Clinic, 1: Branch, 2: User}> */
    private array $clinics = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);
        $this->clinics['optical'] = $this->makeClinic('Bright Optics', ['optical']);
        $this->clinics['clinic'] = $this->makeClinic('Hill Eye Clinic', ['clinical', 'spectacle_orders']);
        $this->clinics['other'] = $this->makeClinic('Other Eye Clinic', ['clinical', 'spectacle_orders']);
    }

    private function makeClinic(string $name, array $features, string $role = 'Super Admin'): array
    {
        $clinic = Clinic::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'active', 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $plan = SubscriptionPlan::create(['name' => $name.' plan', 'code' => 'plan-'.$clinic->id, 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => $features, 'base_price' => 0, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth(), 'feature_snapshot' => $features]);

        return [$clinic, $branch, $this->staff($clinic, $branch, $role, $name.' owner')];
    }

    private function staff(Clinic $clinic, Branch $branch, string $roleName, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $clinic->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($user->id, ['status' => 'active', 'is_default' => true]);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        DB::table('branch_user_role')->insert(['branch_id' => $branch->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    /** Sign in as someone at that clinic (its owner unless another user is given). */
    private function as(string $key, ?User $user = null): User
    {
        [$clinic, $branch, $owner] = $this->clinics[$key];
        $user ??= $owner;
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);
        app(BranchRoleManager::class)->hydrate($user, $branch);
        $this->actingAs($user);

        return $user;
    }

    private function partner(string $name = 'Hill Eye Clinic'): OpticalPartnerClinic
    {
        $this->as('optical');

        return OpticalPartnerClinic::create(['name' => $name, 'phone' => '0240000001', 'billing_terms' => 'on_account']);
    }

    private function linked(): OpticalPartnerClinic
    {
        $partner = $this->partner();
        $invite = app(OpticalLinkService::class)->createInvite($partner, auth()->user());
        $this->as('clinic');
        app(OpticalLinkService::class)->acceptInvite($invite->code, auth()->user());

        return $partner->fresh();
    }

    /** A refraction at the sending clinic, authorised for dispensing. */
    private function refraction(bool $authorised = true): Refractions
    {
        $this->as('clinic');
        $patient = Patient::createWithGeneratedPxNumber(['user_id' => auth()->id(), 'name' => 'Ama Mensah', 'contact' => '0201234567', 'gender' => 'Female', 'address' => 'Secret Street 4']);
        $clearance = CashierPatientClearance::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_date' => now()->toDateString(), 'payment_status' => 'Paid']);
        $consultation = Consultations::create(['user_id' => auth()->id(), 'patient_id' => $patient->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Private complaint']);

        return Refractions::create(['user_id' => auth()->id(), 'consultation_id' => $consultation->id,
            'refractionOD' => '-1.25/-0.50x180', 'refractionOS' => '-1.00', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6',
            'subjective_od_sphere' => -1.25, 'subjective_od_cylinder' => -0.5, 'subjective_od_axis' => 180,
            'subjective_os_sphere' => -1.00, 'subjective_os_add' => 2.00, 'pd' => 63, 'lensType' => 'Progressive',
            'dispensing_required' => true, 'dispensing_authorized_at' => $authorised ? now() : null]);
    }

    private function send(Refractions $refraction, int $partnerId): OpticalOrderRequest
    {
        $this->as('clinic');

        return app(OpticalLinkService::class)->send($refraction->fresh(['consultation.patient', 'lensOrder']), $partnerId,
            ['frame_choice' => 'own_frame', 'frame_notes' => 'Black metal', 'lens_notes' => 'Photochromic', 'urgency' => 'urgent'], auth()->user());
    }

    public function test_an_optical_centre_invites_and_the_clinic_owner_links_with_the_code(): void
    {
        $partner = $this->partner();
        Livewire::test(PartnerClinicsComponent::class)->call('createInvite', $partner->id)->assertHasNoErrors();
        $invite = OpticalLinkInvite::firstOrFail();
        $this->assertSame(8, strlen($invite->code));
        $this->assertTrue($invite->expires_at->between(now()->addDays(6), now()->addDays(8)));

        $this->as('clinic');
        Livewire::withQueryParams(['code' => strtolower($invite->code)])->test(OpticalCentresComponent::class)
            ->assertSet('previewInviteId', $invite->id)->assertSee('Bright Optics')->assertSee('on account')
            ->call('accept')->assertHasNoErrors();

        $partner = OpticalPartnerClinic::withoutGlobalScopes()->find($partner->id);
        $this->assertSame($this->clinics['clinic'][0]->id, (int) $partner->linked_clinic_id);
        $this->assertSame('active', $partner->link_status);
        $this->assertNotNull($invite->fresh()->accepted_at);
        // The code can't be used again, by this clinic or another.
        $this->as('other');
        $this->expectException(ValidationException::class);
        app(OpticalLinkService::class)->findInvite($invite->code);
    }

    public function test_bad_codes_and_non_managers_cannot_link(): void
    {
        $partner = $this->partner();
        $expired = app(OpticalLinkService::class)->createInvite($partner, auth()->user());
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->as('clinic');
        Livewire::test(OpticalCentresComponent::class)->set('code', $expired->code)->call('check')->assertHasErrors('code')->assertSet('previewInviteId', null);

        // Its own invite: the optical centre can't link to itself.
        $this->as('optical');
        $own = app(OpticalLinkService::class)->createInvite($partner, auth()->user());
        try {
            app(OpticalLinkService::class)->findInvite($own->code);
            $this->fail('An optical centre linked to itself.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('your own clinic', $e->getMessage());
        }

        // A receptionist can't accept for the clinic.
        [$clinic, $branch] = $this->clinics['clinic'];
        $this->as('clinic', $this->staff($clinic, $branch, 'Secretary', 'Front desk'));
        $this->get(route('secretary.optical-centres', ['code' => $own->code]))->assertForbidden();
        $this->assertFalse(app(OpticalLinkService::class)->canManageLinks(auth()->user()));
    }

    public function test_a_linked_clinic_sends_only_name_phone_and_prescription(): void
    {
        $partner = $this->linked();
        $refraction = $this->refraction();

        Livewire::test(SendToOpticalComponent::class)->call('start', $refraction->id)->assertSet('partnerId', $partner->id)
            ->set('frameChoice', 'note')->set('frameNotes', 'Tortoiseshell')->set('notes', 'Patient travels Friday')
            ->call('send')->assertHasNoErrors()->assertDispatched('optical-request-sent');

        $request = OpticalOrderRequest::firstOrFail();
        $this->assertSame('sent', $request->status);
        $this->assertSame($this->clinics['optical'][0]->id, (int) $request->optical_clinic_id);
        $this->assertSame('Ama Mensah', $request->patient_name);
        $this->assertSame('0201234567', $request->patient_phone);
        $this->assertSame(-1.25, (float) data_get($request->rx, 'od.sph'));
        $this->assertSame(2.0, (float) data_get($request->rx, 'os.add'));
        $this->assertSame('Progressive', $request->lens_type);
        $stored = json_encode($request->getAttributes());
        $this->assertStringNotContainsString('Secret Street', $stored);
        $this->assertStringNotContainsString('Private complaint', $stored);

        // Not twice while it is open, and the Spectacles page shows it as sent.
        $this->expectException(ValidationException::class);
        $this->send($refraction, $partner->id);
    }

    public function test_sending_needs_an_authorised_refraction_an_active_link_and_an_optical_plan(): void
    {
        $partner = $this->linked();
        try {
            $this->send($this->refraction(false), $partner->id);
            $this->fail('Sent a refraction not authorised for dispensing.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Paused by the optical centre: no sending, and only the optical centre can resume.
        $this->as('optical');
        app(OpticalLinkService::class)->setLinkStatus($partner, 'paused', auth()->user());
        $refraction = $this->refraction();
        $this->assertTrue(app(OpticalLinkService::class)->sendTargets()->isEmpty());
        try {
            $this->send($refraction, $partner->id);
            $this->fail('Sent over a paused link.');
        } catch (ValidationException) {
        }
        try {
            app(OpticalLinkService::class)->setLinkStatus($partner, 'active', auth()->user());
            $this->fail('The clinic resumed a link the optical centre paused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('link', $e->errors());
        }
        $this->as('optical');
        app(OpticalLinkService::class)->setLinkStatus($partner, 'active', auth()->user());

        // The optical centre's subscription no longer has the optical module.
        ClinicSubscription::where('clinic_id', $this->clinics['optical'][0]->id)->update(['feature_snapshot' => json_encode(['clinical'])]);
        app(\App\Services\SubscriptionService::class)->forgetCurrent();
        $this->as('clinic');
        $this->assertTrue(app(OpticalLinkService::class)->sendTargets()->isEmpty());
    }

    public function test_each_side_only_sees_its_own_requests(): void
    {
        $partner = $this->linked();
        $request = $this->send($this->refraction(), $partner->id);

        $this->as('other');
        $this->assertSame(0, OpticalOrderRequest::forSender($this->clinics['other'][0]->id)->count());
        Livewire::test(SentToOpticalComponent::class)->assertDontSee('Ama Mensah');
        try {
            Livewire::test(SentToOpticalComponent::class)->call('cancel', $request->id);
            $this->fail('Another clinic cancelled the request.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        try {
            app(OpticalLinkService::class)->incomingRequest($request->id);
            $this->fail('Another clinic opened the request as an optical centre.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }

        $this->as('optical');
        Livewire::test(IncomingRequestsComponent::class)->assertSee('Ama Mensah')->assertSee('Hill Eye Clinic');
        $this->as('clinic');
        Livewire::test(SentToOpticalComponent::class)->assertSee('Ama Mensah')->assertSee('Bright Optics');
    }

    public function test_question_answer_then_accept_into_a_partner_order_that_the_clinic_follows(): void
    {
        $partner = $this->linked();
        $request = $this->send($this->refraction(), $partner->id);

        $this->as('optical');
        Livewire::test(IncomingRequestsComponent::class)->call('open', $request->id)->call('startAction', 'query')
            ->set('question', 'Confirm the right axis?')->call('sendQuestion')->assertHasNoErrors();
        $this->assertSame('query', $request->fresh()->status);

        $this->as('clinic');
        $this->assertSame(1, OpticalLinkService::senderQueryCount());
        Livewire::test(SentToOpticalComponent::class)->call('startAnswer', $request->id)->set('answer', 'Yes, 180.')->call('sendAnswer')->assertHasNoErrors();
        $this->assertSame('sent', $request->fresh()->status);

        $this->as('optical');
        $this->assertSame(1, OpticalLinkService::newIncomingCount());
        Livewire::test(IncomingRequestsComponent::class)->call('open', $request->id)->call('accept')
            ->assertRedirect(route('optical.orders.create', ['request_id' => $request->id]));
        Livewire::withQueryParams(['request_id' => $request->id])->test(OpticalOrderCreateComponent::class)
            ->assertSet('linkRequestId', $request->id)->assertSet('order_source', 'partner')->assertSet('partner_id', $partner->id)
            ->assertSet('customer_name', 'Ama Mensah')->assertSet('reference', $request->sender_reference)
            ->assertSet('rx_od_sph', -1.25)->assertSet('rx_od_axis', 180)->assertSet('rx_os_add', 2.0)
            ->assertSet('frame_source', 'customer')->assertSee('Online order from');

        // What the form does on save: the order and the accepted request together.
        $order = LensOrder::create(['order_id' => 'OPT-LINK-1', 'status' => 'Pending', 'order_source' => 'partner', 'partner_clinic_id' => $partner->id,
            'customer_name' => 'Ama Mensah', 'frame_price' => 0, 'lens_price' => 0, 'pickUpDate' => now()->addWeek(), 'user_id' => auth()->id()]);
        app(OpticalLinkService::class)->markAccepted($request->id, $order);
        $this->assertSame('accepted', $request->fresh()->status);
        $this->assertSame('OPT-LINK-1', $request->fresh()->order_number);

        $order->update(['status' => 'In Production']);
        $this->assertSame('In Production', $request->fresh()->order_status);
        $this->assertSame('In Production', $request->fresh()->progressLabel());

        // Accepted refractions leave the clinic's "needs order" queue; the clinic can no longer cancel.
        $this->as('clinic');
        try {
            app(OpticalLinkService::class)->cancel($request->fresh(), auth()->user());
            $this->fail('Cancelled an accepted request.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_a_request_cancelled_by_the_clinic_cannot_be_accepted_and_unlinking_keeps_history(): void
    {
        $partner = $this->linked();
        $request = $this->send($this->refraction(), $partner->id);
        Livewire::test(SentToOpticalComponent::class)->call('cancel', $request->id);
        $this->assertSame('cancelled', $request->fresh()->status);

        $this->as('optical');
        $order = LensOrder::create(['order_id' => 'OPT-LATE', 'status' => 'Pending', 'order_source' => 'partner', 'partner_clinic_id' => $partner->id,
            'frame_price' => 0, 'lens_price' => 0, 'pickUpDate' => now()->addWeek(), 'user_id' => auth()->id()]);
        try {
            app(OpticalLinkService::class)->markAccepted($request->id, $order);
            $this->fail('Accepted a cancelled request.');
        } catch (ValidationException) {
        }

        $this->as('clinic');
        Livewire::test(OpticalCentresComponent::class)->call('setLink', $partner->id, 'ended');
        $this->assertTrue(app(OpticalLinkService::class)->sendTargets()->isEmpty());
        $this->assertSame(1, OpticalOrderRequest::forSender($this->clinics['clinic'][0]->id)->count());
        $this->as('optical');
        $this->assertSame(1, OpticalOrderRequest::forOptical($this->clinics['optical'][0]->id)->count());

        // A new invite links the same clinic again.
        $invite = app(OpticalLinkService::class)->createInvite($partner->fresh(), auth()->user());
        $this->as('clinic');
        app(OpticalLinkService::class)->acceptInvite($invite->code, auth()->user());
        $this->assertSame('active', OpticalPartnerClinic::withoutGlobalScopes()->find($partner->id)->link_status);
    }

    /* ---------------------------------------------------------------- phase 2: quotes, money, remakes */

    /** The optical centre prices the request in the order form: a custom frame at the given price, the wearer's own lenses. */
    private function priceInForm(OpticalOrderRequest $request, float $price)
    {
        $this->as('optical');

        return Livewire::withQueryParams(['request_id' => $request->id])->test(OpticalOrderCreateComponent::class)
            ->set('frame_source', 'custom')->set('frame_model_number', 'Black metal')->set('frame_price', $price)
            ->set('lens_fulfilment_source', 'customer')->set('lens_price', 0)
            ->call('createOrder');
    }

    /** Send, quote, approve and start: an accepted job on the clinic's account. */
    private function acceptedJob(OpticalPartnerClinic $partner, float $price = 300): OpticalOrderRequest
    {
        $request = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($request, $price)->assertHasNoErrors();
        $this->as('clinic');
        app(OpticalLinkService::class)->approveQuote($request->fresh(), auth()->user());
        $this->as('optical');
        app(OpticalLinkService::class)->startJob($request->fresh());

        return $request->fresh();
    }

    public function test_a_priced_job_goes_to_the_clinic_as_a_quote_and_starts_only_once_approved(): void
    {
        $partner = $this->linked();
        $request = $this->send($this->refraction(), $partner->id);

        $this->priceInForm($request, 300)->assertHasNoErrors()->assertRedirect(route('optical.requests'));
        $request->refresh();
        $quote = LensOrder::findOrFail($request->lens_order_id);
        $this->assertSame(['quoted', 'Quotation'], [$request->status, $quote->status]);
        $this->assertSame(300.0, (float) $request->quote_total);
        $this->assertSame('Black metal', collect($request->quote_lines)->pluck('label')->contains(fn ($label) => str_contains($label, 'Black metal')) ? 'Black metal' : null);

        // Not before the clinic approves: neither from Incoming Orders nor the order panel's Convert.
        try {
            app(OpticalOrderWorkflowService::class)->activateQuotation($quote->id, 0, 'cash');
            $this->fail('Started a quote the clinic had not approved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('approve', $e->getMessage());
        }

        $this->as('clinic');
        $this->assertSame(1, OpticalLinkService::senderQueryCount());
        Livewire::test(SentToOpticalComponent::class)->assertSee('Trade price')->assertSee('300.00')
            ->call('approveQuote', $request->id)->assertHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);

        $this->as('optical');
        $this->assertSame(1, OpticalLinkService::newIncomingCount());
        Livewire::test(IncomingRequestsComponent::class)->call('setTab', 'quotes')->assertSee('Ama Mensah')
            ->call('open', $request->id)->call('startJob')->assertHasNoErrors();
        $request->refresh();
        $job = LensOrder::findOrFail($request->lens_order_id);
        $this->assertSame(['accepted', 'Pending'], [$request->status, $job->status]);
        $this->assertSame([300.0, 0.0], [(float) $request->order_total, (float) $request->order_paid]);
        $this->assertSame(0.0, app(OpticalOrderWorkflowService::class)->minimumDeposit($job));
        $this->assertSame($job->order_id, $request->order_number);
    }

    public function test_declined_and_expired_quotes(): void
    {
        $partner = $this->linked();
        $request = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($request, 250);

        $this->as('clinic');
        Livewire::test(SentToOpticalComponent::class)->call('startDecline', $request->id)->call('declineQuote')->assertHasErrors('declineReason')
            ->set('declineReason', 'Too dear for the patient')->call('declineQuote')->assertHasNoErrors();
        $this->assertSame('quote_declined', $request->fresh()->status);
        $this->assertNull(app(OpticalLinkService::class)->openRequestFor($request->refraction_id));

        // The optical centre tidies its quotation away.
        $this->as('optical');
        Livewire::test(IncomingRequestsComponent::class)->call('open', $request->id)->call('cancelQuote')->assertHasNoErrors();
        $this->assertSame('Cancelled', LensOrder::find($request->fresh()->lens_order_id)->status);
        $this->assertSame('quote_declined', $request->fresh()->status);

        // An expired quote can't be approved; it goes back for a new one, which replaces the old quotation.
        $second = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($second, 200);
        $second->refresh();
        $second->forceFill(['quote_valid_until' => now()->subDay()])->save();
        $this->as('clinic');
        try {
            app(OpticalLinkService::class)->approveQuote($second, auth()->user());
            $this->fail('Approved an expired quote.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quote', $e->errors());
        }
        Livewire::test(SentToOpticalComponent::class)->assertSee('Ask for a new quote')->call('askNewQuote', $second->id);
        $this->assertSame('sent', $second->fresh()->status);
        $old = $second->lens_order_id;
        $this->priceInForm($second->fresh(), 180)->assertHasNoErrors();
        $this->assertSame('Cancelled', LensOrder::find($old)->status);
        $this->assertSame(['quoted', 180.0], [$second->fresh()->status, (float) $second->fresh()->quote_total]);
        $this->assertNotSame($old, $second->fresh()->lens_order_id);
    }

    public function test_the_clinic_pays_into_its_expenses_and_the_optical_centre_confirms(): void
    {
        $partner = $this->linked();
        $job = $this->acceptedJob($partner, 300);
        [$clinic] = $this->clinics['clinic'];
        [$optical] = $this->clinics['optical'];

        $this->as('clinic');
        Livewire::test(OpticalOwedComponent::class)->assertSee('Still to pay')->assertSee('300.00')
            ->call('openPay', $partner->id)->assertSet('payAmount', '300.00')
            ->set('payAmount', '120')->set('payMethod', 'momo')->set('payReference', 'MOMO-77')->call('recordPayment')->assertHasNoErrors();
        $payment = OpticalLinkPayment::firstOrFail();
        $this->assertSame(['pending', 120.0, [$job->id => 120.0]], [$payment->status, (float) $payment->amount, array_map('floatval', $payment->allocations)]);
        $expense = Expense::withoutGlobalScopes()->findOrFail($payment->expense_id);
        $this->assertSame([$clinic->id, 120.0, 'momo', 'clinic'], [(int) $expense->clinic_id, (float) $expense->amount, $expense->payment_method, $expense->business_line]);
        $this->assertSame(OpticalLinkPayment::EXPENSE_CATEGORY, $expense->category?->name);

        // More than is left (300 - 120 waiting) is refused.
        try {
            app(OpticalLinkPaymentService::class)->record($partner->id, 200, 'cash', null, null, null, auth()->user());
            $this->fail('Paid more than is owed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payAmount', $e->errors());
        }

        // The optical centre confirms: its own receipt settles the job, and the clinic's view follows.
        $this->as('optical');
        $this->assertSame(1, OpticalLinkPaymentService::waitingCount());
        Livewire::test(PartnerClinicsComponent::class)->call('openPartner', $partner->id)->assertSee('Payments from the clinic to confirm')
            ->call('confirmLinkPayment', $payment->id)->assertHasNoErrors();
        $receipt = OpticalPartnerPayment::firstOrFail();
        $this->assertSame([$optical->id, 120.0], [(int) $receipt->clinic_id, (float) $receipt->amount]);
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame(180.0, app(OpticalPartnerAccountService::class)->balance($partner->fresh()));
        $this->assertSame(120.0, (float) $job->fresh()->order_paid);

        // A second payment the optical centre did not receive: disputed, then withdrawn with its expense.
        $this->as('clinic');
        $second = app(OpticalLinkPaymentService::class)->record($partner->id, 50, 'bank_transfer', null, null, null, auth()->user());
        $this->as('optical');
        Livewire::test(PartnerClinicsComponent::class)->call('openPartner', $partner->id)->call('startDispute', $second->id)
            ->set('disputeReason', 'Nothing in the bank yet')->call('disputeLinkPayment')->assertHasNoErrors();
        $this->as('clinic');
        Livewire::test(OpticalOwedComponent::class)->assertSee('Nothing in the bank yet')->call('withdraw', $second->id)->assertHasNoErrors();
        $this->assertSame('withdrawn', $second->fresh()->status);
        $this->assertSoftDeleted('expenses', ['id' => $second->expense_id]);

        // Cash the optical centre took at its counter: the clinic adds it to its expenses once.
        $this->as('optical');
        app(OpticalPartnerAccountService::class)->recordPayment($partner->fresh(), 30, 'cash');
        $this->as('clinic');
        $service = app(OpticalLinkPaymentService::class);
        $receipts = $service->unbookedReceipts($partner->id);
        $this->assertCount(1, $receipts);
        $this->assertSame(30.0, $receipts->first()['amount']);
        Livewire::test(OpticalOwedComponent::class)->assertSee('Paid but not in your expenses yet')
            ->call('bookReceipt', $partner->id, $receipts->first()['payment']->id)->assertHasNoErrors();
        $this->assertCount(0, $service->unbookedReceipts($partner->id));
        $this->assertSame(30.0, (float) Expense::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('reference', $receipts->first()['payment']->receipt_number)->value('amount'));
        $this->assertSame(150.0, $service->summary()->first()['to_pay']);
    }

    public function test_a_remake_is_asked_for_by_the_clinic_and_placed_free_by_the_optical_centre(): void
    {
        $partner = $this->linked();
        $job = $this->acceptedJob($partner, 300);

        // Not until the glasses are ready.
        $this->as('clinic');
        try {
            app(OpticalLinkService::class)->requestRemake($job, 'lab_error', null, auth()->user());
            $this->fail('Asked for a remake of glasses still in the lab.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->as('optical');
        $workflow = app(OpticalOrderWorkflowService::class);
        $workflow->transition($job->lens_order_id, 'Sent to Lab');
        $workflow->transition($job->lens_order_id, 'Ready for Collection');
        $this->assertTrue($job->fresh()->canAskRemake());

        $this->as('clinic');
        Livewire::test(SentToOpticalComponent::class)->call('setTab', 'accepted')->call('startRemake', $job->id)
            ->set('remakeReason', 'lab_error')->set('remakeNote', 'Left lens scratched')->call('sendRemake')->assertHasNoErrors();
        $remake = OpticalOrderRequest::where('kind', 'remake')->firstOrFail();
        $this->assertSame([$job->id, 'sent', 'lab_error'], [(int) $remake->remake_of_request_id, $remake->status, $remake->remake_reason]);

        $this->as('optical');
        Livewire::test(IncomingRequestsComponent::class)->call('open', $remake->id)->assertSee('Remake')->call('accept')
            ->assertRedirect(route('optical.orders.create', ['remake_of' => $job->lens_order_id, 'request_id' => $remake->id]));
        Livewire::withQueryParams(['remake_of' => $job->lens_order_id, 'request_id' => $remake->id])->test(OpticalOrderCreateComponent::class)
            ->assertSet('linkRequestId', $remake->id)->assertSet('remakeOfId', $job->lens_order_id)->assertSet('remake_charge', 'free')
            ->set('lens_fulfilment_source', 'customer')->call('createOrder')->assertHasNoErrors();
        $remake->refresh();
        $order = LensOrder::findOrFail($remake->lens_order_id);
        $this->assertSame(['accepted', 'Pending', $job->lens_order_id, 0.0], [$remake->status, $order->status, (int) $order->remake_of_id, (float) $order->total]);
    }

    public function test_quotes_and_payments_stay_with_their_own_clinics(): void
    {
        $partner = $this->linked();
        $request = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($request, 100);

        $this->as('other');
        try {
            app(OpticalLinkService::class)->approveQuote($request->fresh(), auth()->user());
            $this->fail('Another clinic approved the quote.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        try {
            app(OpticalLinkPaymentService::class)->record($partner->id, 10, 'cash', null, null, null, auth()->user());
            $this->fail('Another clinic paid on this account.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        $this->assertTrue(app(OpticalLinkPaymentService::class)->summary()->isEmpty());

        // A receptionist can't record payments.
        [$clinic, $branch] = $this->clinics['clinic'];
        $this->as('clinic', $this->staff($clinic, $branch, 'Secretary', 'Front desk'));
        $this->get(route('secretary.optical-owed'))->assertForbidden();
    }

    /* ---------------------------------------------------------------- agreed terms: trade %, credit, auto-approve, platform */

    public function test_the_agreed_trade_discount_comes_off_the_quote_on_its_own_line(): void
    {
        $partner = $this->linked();
        $this->as('optical');
        Livewire::test(PartnerClinicsComponent::class)->call('openPartner', $partner->id)
            ->set('terms', ['lens' => '10', 'frame' => '95', 'service' => '', 'credit' => ''])->call('saveTerms')->assertHasErrors('terms.frame')
            ->set('terms', ['lens' => '10', 'frame' => '20', 'service' => '', 'credit' => ''])->call('saveTerms')->assertHasNoErrors();
        $this->assertSame([10.0, 20.0, 0.0], array_values($partner->fresh()->tradePercents()));

        // The clinic sees the terms; another optical centre's staff or a clinic can't set them.
        $this->as('clinic');
        Livewire::test(OpticalCentresComponent::class)->assertSee('lenses 10%, frames 20%');

        $request = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($request, 300)->assertHasNoErrors();
        $request->refresh();
        $quote = LensOrder::findOrFail($request->lens_order_id);
        // Frame 300 less 20% = 240; the wearer's own lenses cost nothing.
        $this->assertSame([240.0, 240.0, 60.0], [(float) $request->quote_total, (float) $quote->total, (float) $quote->discount_amount]);
        $this->assertContains('Agreed trade discount (lenses 10%, frames 20%)', collect($request->quote_lines)->pluck('label')->all());
        $this->assertSame(-60.0, (float) collect($request->quote_lines)->firstWhere('label', 'Agreed trade discount (lenses 10%, frames 20%)')['amount']);

        // Ordinary partner jobs (not from a link request) are priced as before.
        $this->as('optical');
        $form = Livewire::withQueryParams(['partner_id' => $partner->id])->test(OpticalOrderCreateComponent::class);
        $form->set('frame_source', 'custom')->set('frame_model_number', 'Red')->set('frame_price', 100);
        $plain = $form->instance()->priceBreakdown();
        $this->assertSame([0.0, 100.0], [$plain['trade'], $plain['total']]);
    }

    public function test_quotes_within_the_clinics_limit_are_approved_and_started_at_once(): void
    {
        $partner = $this->linked();
        $this->as('clinic');
        Livewire::test(OpticalCentresComponent::class)->set("autoApprove.{$partner->id}", 'abc')->call('saveAutoApprove', $partner->id)->assertHasErrors("autoApprove.{$partner->id}")
            ->set("autoApprove.{$partner->id}", '250')->call('saveAutoApprove', $partner->id)->assertHasNoErrors();
        $this->assertSame(250.0, $partner->fresh()->auto_approve_limit);

        // Within the limit: approved and the job started, with no one pressing anything.
        $small = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($small, 200)->assertHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'approved at once'));
        $small->refresh();
        $this->assertSame(['accepted', true], [$small->status, $small->auto_approved]);
        $this->assertSame('Pending', LensOrder::find($small->lens_order_id)->status);
        $this->as('clinic');
        Livewire::test(SentToOpticalComponent::class)->assertSee('approved automatically');

        // Over it: waits for the clinic as before.
        $big = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($big, 400)->assertHasNoErrors();
        $this->assertSame(['quoted', false], [$big->fresh()->status, $big->fresh()->auto_approved]);

        // Only owners and managers set it.
        [$clinic, $branch] = $this->clinics['clinic'];
        $this->as('clinic', $this->staff($clinic, $branch, 'Secretary', 'Desk'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->setAutoApprove($partner->id, '1000', auth()->user());
    }

    public function test_at_the_credit_limit_the_clinic_cannot_send_new_jobs_until_it_pays(): void
    {
        $partner = $this->linked();
        $this->as('optical');
        app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->setTerms($partner, ['credit' => '300'], auth()->user());
        $job = $this->acceptedJob($partner->fresh(), 300);
        $this->assertTrue(\App\Services\OpticalLinks\OpticalLinkTermsService::overLimit($partner->fresh()));

        $refraction = $this->refraction();
        try {
            $this->send($refraction, $partner->id);
            $this->fail('Sent a job over the credit limit.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('credit limit', $e->errors()['partnerId'][0]);
        }
        Livewire::test(OpticalCentresComponent::class)->assertSee('Credit limit reached');

        // An auto-approve limit never takes the clinic past its credit: such a quote waits.
        $this->as('clinic');
        app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->setAutoApprove($partner->id, '1000', auth()->user());
        $this->as('optical');
        app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->setTerms($partner, ['credit' => '400'], auth()->user());
        $next = $this->send($this->refraction(), $partner->id);
        $this->priceInForm($next, 150)->assertHasNoErrors();
        $this->assertSame('quoted', $next->fresh()->status);

        // Paid and confirmed: below the limit again.
        $this->as('clinic');
        $payment = app(OpticalLinkPaymentService::class)->record($partner->id, 300, 'cash', null, null, null, auth()->user());
        $this->as('optical');
        app(OpticalLinkPaymentService::class)->confirm($payment, auth()->user());
        $this->assertSame(0.0, \App\Services\OpticalLinks\OpticalLinkTermsService::owed($partner->fresh()));
        $this->assertSame('sent', $this->send($refraction, $partner->id)->status);
        $this->assertSame('accepted', $job->fresh()->status);
    }

    public function test_the_platform_sees_every_link_and_can_pause_it_with_a_reason(): void
    {
        $partner = $this->linked();
        $this->acceptedJob($partner, 120);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->withSession(['workspace_mode' => 'platform']);
        app(TenantContext::class)->clear();

        $this->get(route('platform.optical-links'))->assertOk()->assertSee('Bright Optics')->assertSee('Hill Eye Clinic')->assertSee('120.00');
        Livewire::test(\App\Livewire\Platform\OpticalLinksComponent::class)->call('open', $partner->id)
            ->call('setStatus', 'paused')->assertHasErrors('reason')
            ->set('reason', 'Payment dispute under review')->call('setStatus', 'paused')->assertHasNoErrors();
        $partner->refresh();
        $this->assertSame(['paused', true, 'Payment dispute under review'], [$partner->link_status, $partner->link_paused_by_platform, $partner->link_status_reason]);

        // Neither clinic can resume it, and the clinic can't send.
        foreach (['optical', 'clinic'] as $side) {
            $this->as($side);
            try {
                app(OpticalLinkService::class)->setLinkStatus($partner, 'active', auth()->user());
                $this->fail('A clinic lifted the platform\'s pause.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Nexus support', $e->errors()['link'][0]);
            }
        }
        Livewire::test(OpticalCentresComponent::class)->assertSee('Paused by Nexus support: Payment dispute under review');
        $this->assertTrue(app(OpticalLinkService::class)->sendTargets()->isEmpty());

        // Only a platform admin steps in; the platform resumes its own pause.
        try {
            app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->platformSetStatus($partner->id, 'active', null, auth()->user());
            $this->fail('A clinic used the platform action.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }
        app(\App\Services\OpticalLinks\OpticalLinkTermsService::class)->platformSetStatus($partner->id, 'active', null, $admin);
        $this->assertSame(['active', false, null], [$partner->fresh()->link_status, $partner->fresh()->link_paused_by_platform, $partner->fresh()->link_status_reason]);

        // Clinic staff can't open the page.
        $this->as('clinic');
        $this->assertContains($this->get(route('platform.optical-links'))->status(), [302, 403]);
    }

    /* ---------------------------------------------------------------- ordering page for clinics not on Nexus */

    /** Turn on the ordering page for a new partner; returns [partner, token, pin]. */
    private function orderPage(string $name = 'Roadside Clinic'): array
    {
        $partner = $this->partner($name);
        $component = Livewire::test(PartnerClinicsComponent::class)->call('openPartner', $partner->id)
            ->set('pagePin', '123456')->call('setOrderPage', $partner->id)->assertHasErrors('pagePin')
            ->set('pagePin', '')->call('setOrderPage', $partner->id)->assertHasNoErrors();
        $pin = $component->get('shownPin');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $partner->refresh();
        $this->assertTrue($partner->hasOrderPage());
        $this->assertArrayNotHasKey('page_pin_hash', $partner->toArray());
        $this->assertNotSame($pin, $partner->page_pin_hash);

        return [$partner, $partner->page_token, $pin];
    }

    private function pageOrder(array $overrides = []): array
    {
        return $overrides + ['sender_name' => 'Nurse Esi', 'patient_name' => 'Kofi Boateng', 'patient_phone' => '0244000111', 'reference' => 'F-17',
            'od_sph' => '-1.25', 'od_cyl' => '-0.50', 'od_axis' => '90', 'os_sph' => '-1.00', 'pd' => '62', 'lens_type' => 'Single vision',
            'frame_choice' => 'patient_chooses', 'urgency' => 'normal'];
    }

    public function test_a_clinic_not_on_nexus_orders_and_follows_jobs_with_a_link_and_pin(): void
    {
        [$partner, $token, $pin] = $this->orderPage();
        app(TenantContext::class)->clear();
        auth()->logout();

        // Without the PIN: only the PIN form; nothing about orders. A wrong link is not found.
        $this->get(route('order-page.show', $token))->assertOk()->assertSee('Enter your PIN')->assertDontSee('Your orders')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/order/'.str_repeat('A', 40))->assertNotFound();
        $this->post(route('order-page.send', $token), $this->pageOrder())->assertRedirect(route('order-page.show', $token));
        $this->assertSame(0, OpticalOrderRequest::count());

        $this->post(route('order-page.pin', $token), ['pin' => $pin])->assertRedirect(route('order-page.show', $token));
        $this->get(route('order-page.show', $token))->assertSee('Your orders')->assertSee('Send a new order');

        // A cylinder needs an axis; then the order goes to Incoming Orders.
        $this->post(route('order-page.send', $token), $this->pageOrder(['od_axis' => '']))->assertSessionHasErrors('od_axis');
        $this->post(route('order-page.send', $token), $this->pageOrder())->assertSessionHasNoErrors();
        $request = OpticalOrderRequest::sole();
        $this->assertSame(['page', null, 'Nurse Esi', 'Kofi Boateng', 'sent'], [$request->source, $request->sender_clinic_id, $request->sender_name, $request->patient_name, $request->status]);
        $this->assertEquals([-1.25, -0.5, 90], [$request->rx['od']['sph'], $request->rx['od']['cyl'], $request->rx['od']['axis']]);

        // The optical centre asks a question; they answer on the page.
        $this->as('optical');
        Livewire::test(IncomingRequestsComponent::class)->assertSee('Roadside Clinic (ordering page, Nurse Esi)')
            ->call('open', $request->id)->set('question', 'Which frame size?')->call('sendQuestion')->assertHasNoErrors();
        app(TenantContext::class)->clear();
        auth()->logout();
        $this->get(route('order-page.show', $token))->assertSee('Which frame size?');
        $this->post(route('order-page.answer', [$token, $request->id]), ['answer' => 'Medium'])->assertSessionHasNoErrors();
        $this->assertSame(['sent', 'Medium'], [$request->fresh()->status, $request->fresh()->query_answer]);

        // Priced in the order form: placed straight away as a partner job (no quote on the page).
        $this->as('optical');
        Livewire::withQueryParams(['request_id' => $request->id])->test(OpticalOrderCreateComponent::class)
            ->set('frame_source', 'custom')->set('frame_model_number', 'Black metal')->set('frame_price', 250)
            ->set('lens_fulfilment_source', 'customer')->set('lens_price', 0)->call('createOrder')->assertHasNoErrors();
        $request->refresh();
        $job = LensOrder::findOrFail($request->lens_order_id);
        $this->assertSame(['accepted', 'Pending', 250.0, $partner->id], [$request->status, $job->status, (float) $job->total, (int) $job->partner_clinic_id]);
        app(OpticalOrderWorkflowService::class)->transition($job->id, 'Sent to Lab');

        app(TenantContext::class)->clear();
        auth()->logout();
        $this->get(route('order-page.show', $token))->assertSee('Sent to Lab')->assertSee($job->order_id);
    }

    public function test_wrong_pins_lock_the_page_and_a_new_pin_or_link_signs_everyone_out(): void
    {
        [$partner, $token, $pin] = $this->orderPage();
        $optical = auth()->user();
        app(TenantContext::class)->clear();
        auth()->logout();

        foreach (range(1, 4) as $try) {
            $this->post(route('order-page.pin', $token), ['pin' => '000001'])->assertSessionHasErrors('pin');
        }
        $this->post(route('order-page.pin', $token), ['pin' => '000001'])->assertSessionHasErrors(['pin' => 'Too many wrong PINs. The page is locked for 15 minutes.']);
        // Locked: even the right PIN waits.
        $this->post(route('order-page.pin', $token), ['pin' => $pin])->assertSessionHasErrors('pin');
        $this->travel(16)->minutes();
        $this->post(route('order-page.pin', $token), ['pin' => $pin])->assertSessionHasNoErrors();
        $this->get(route('order-page.show', $token))->assertSee('Your orders');

        // A new PIN signs this browser out.
        $this->as('optical', $optical);
        Livewire::test(PartnerClinicsComponent::class)->call('openPartner', $partner->id)->set('pagePin', '482913')->call('setOrderPage', $partner->id)->assertHasNoErrors();
        app(TenantContext::class)->clear();
        auth()->logout();
        $this->get(route('order-page.show', $token))->assertSee('Enter your PIN');
        $this->post(route('order-page.pin', $token), ['pin' => '482913'])->assertSessionHasNoErrors();

        // A new link: the old one is gone. Turning it off: gone too.
        $this->as('optical', $optical);
        Livewire::test(PartnerClinicsComponent::class)->call('newOrderPageLink', $partner->id);
        $newToken = $partner->fresh()->page_token;
        $this->assertNotSame($token, $newToken);
        auth()->logout();
        $this->get(route('order-page.show', $token))->assertNotFound();
        $this->as('optical', $optical);
        Livewire::test(PartnerClinicsComponent::class)->call('disableOrderPage', $partner->id);
        auth()->logout();
        $this->get(route('order-page.show', $newToken))->assertNotFound();

        // Only managers turn it on.
        [$clinic, $branch] = $this->clinics['optical'];
        $this->as('optical', $this->staff($clinic, $branch, 'Secretary', 'Desk'));
        Livewire::test(PartnerClinicsComponent::class)->call('setOrderPage', $partner->id)->assertForbidden();
    }

    public function test_each_page_sees_only_its_own_orders_and_they_move_to_the_clinic_when_it_links(): void
    {
        [$roadside, $token, $pin] = $this->orderPage('Roadside Clinic');
        [$other, $otherToken, $otherPin] = $this->orderPage('Hill Eye Clinic');
        app(TenantContext::class)->clear();
        auth()->logout();

        $this->post(route('order-page.pin', $otherToken), ['pin' => $otherPin]);
        $this->post(route('order-page.send', $otherToken), $this->pageOrder(['patient_name' => 'Secret Patient']));
        $this->post(route('order-page.pin', $token), ['pin' => $pin]);
        $this->post(route('order-page.send', $token), $this->pageOrder());
        $this->get(route('order-page.show', $token))->assertSee('Kofi Boateng')->assertDontSee('Secret Patient');
        // Another page's order can't be cancelled from this page.
        $foreign = OpticalOrderRequest::where('patient_name', 'Secret Patient')->sole();
        $this->post(route('order-page.cancel', [$token, $foreign->id]))->assertNotFound();
        $this->assertSame('sent', $foreign->fresh()->status);

        // Hill Eye Clinic joins Nexus and links: its page order moves to its account, the page closes.
        $this->as('optical');
        $invite = app(OpticalLinkService::class)->createInvite($other->fresh(), auth()->user());
        $this->as('clinic');
        app(OpticalLinkService::class)->acceptInvite($invite->code, auth()->user());
        [$clinic] = $this->clinics['clinic'];
        $this->assertSame((int) $clinic->id, (int) $foreign->fresh()->sender_clinic_id);
        $this->assertFalse($other->fresh()->hasOrderPage());
        Livewire::test(SentToOpticalComponent::class)->assertSee('Secret Patient');
        auth()->logout();
        app(TenantContext::class)->clear();
        $this->get(route('order-page.show', $otherToken))->assertNotFound();
        // Roadside's page is untouched.
        $this->get(route('order-page.show', $token))->assertSee('Kofi Boateng');
    }
}
