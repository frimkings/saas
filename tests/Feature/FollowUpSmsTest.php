<?php

namespace Tests\Feature;

use App\Livewire\Admin\SmsTemplatesComponent;
use App\Models\{Appointments, Clinic, ClinicSubscription, LensOrder, Patient, Setting, SmsLog, SmsTemplate, SubscriptionPlan, User};
use App\Services\Messaging\{FollowUpSms, SmsCreditService, SmsCredentials, SmsDriver};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** The automatic follow-ups: missed appointments, order delays, aftercare and the doctor's recall. */
class FollowUpSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'services.eazisms' => ['url' => 'https://platform.test/sms', 'key' => 'key', 'default_sender' => 'EYEPLATFORM']]);
        $this->app->instance(SmsDriver::class, new class implements SmsDriver {
            public function send(SmsCredentials $credentials, string $to, string $message): array { return ['success' => true, 'message_id' => 'msg']; }
            public function balance(SmsCredentials $credentials): array { return ['success' => true, 'response' => ['balance' => 99]]; }
        });

        $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
        $this->user = User::factory()->create();
        $clinic = Clinic::create(['name' => 'Follow Clinic', 'slug' => 'follow-clinic', 'deployment_mode' => 'hosted']);
        $branch = $clinic->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $clinic->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $branch->users()->attach($this->user->id, ['status' => 'active', 'is_default' => true]);
        $plan = SubscriptionPlan::create(['name' => 'Plan', 'code' => 'plan', 'included_branches' => 1, 'included_users' => 5,
            'storage_limit_mb' => 100, 'features' => ['*'], 'base_price' => 10, 'billing_interval' => 'monthly']);
        ClinicSubscription::create(['clinic_id' => $clinic->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
            'current_period_starts_at' => now()->subDay(), 'current_period_ends_at' => now()->addMonth()]);
        app(TenantContext::class)->set($this->user, $clinic, $branch, [$branch->id]);
        $this->actingAs($this->user);
        Setting::getSettings()->update(['sms_enabled' => true]);
        app(SmsCreditService::class)->add($clinic->id, 100, 'grant', ['note' => 'Test credits']);
        SmsTemplate::ensureDefaults();
    }

    private function switchOn(string ...$keys): void
    {
        SmsTemplate::whereIn('key', $keys)->update(['is_enabled' => true]);
    }

    private function texts(string $key): int
    {
        return SmsLog::where('template_key', $key)->count();
    }

    private function patient(string $name, string $phone = '0241112222'): Patient
    {
        return Patient::createWithGeneratedPxNumber(['user_id' => $this->user->id, 'name' => $name, 'contact' => $phone, 'gender' => 'Female']);
    }

    private function appointment(Patient $patient, string $when, string $status = 'Pending'): Appointments
    {
        return Appointments::create(['patient_id' => $patient->id, 'user_id' => $this->user->id, 'title' => 'Eye test',
            'scheduled_at' => Carbon::parse($when), 'status' => $status, 'reminder_channel' => 'sms']);
    }

    private function order(string $status, array $extra = []): LensOrder
    {
        return LensOrder::create(array_merge(['order_id' => 'OPT-'.uniqid(), 'status' => $status, 'order_source' => 'walk_in',
            'customer_name' => 'Walk In', 'customer_phone' => '0249998888', 'frame_model_number' => 'Frame', 'frame_price' => 0,
            'lens_price' => 0, 'pickUpDate' => '2026-10-03', 'user_id' => $this->user->id], $extra));
    }

    public function test_every_follow_up_starts_switched_off(): void
    {
        $this->appointment($this->patient('Ama'), '2026-09-30 09:00');
        $this->order('Collected', ['collected_at' => now()->subDays(5)]);
        $this->patient('Kofi')->forceFill(['next_exam_due_on' => '2026-10-05'])->save();
        $pending = $this->order('Pending');
        $old = $pending->pickUpDate;
        $pending->update(['pickUpDate' => '2026-10-10']);

        $followUps = app(FollowUpSms::class);
        $followUps->missedAppointments();
        $followUps->aftercare();
        $followUps->clinicalRecalls();
        $this->assertFalse($followUps->orderDateChanged($pending, $old));

        $this->assertSame(0, SmsLog::count());
        // Every message a new clinic gets starts switched off, not only these.
        $this->assertSame(0, SmsTemplate::where('is_enabled', true)->count());
        $this->assertSame(count(\App\Support\Messaging\DefaultSmsTemplates::TEMPLATES), SmsTemplate::count());
    }

    public function test_missed_appointment_is_followed_up_once_the_next_day_but_not_old_or_rebooked_ones(): void
    {
        $this->switchOn('appointment_missed_auto');
        $missed = $this->appointment($ama = $this->patient('Ama'), '2026-09-30 09:00');
        $this->appointment($this->patient('Old Miss', '0241113333'), '2026-09-20 09:00', 'Missed');
        $rebooked = $this->patient('Rebooked', '0241114444');
        $this->appointment($rebooked, '2026-09-30 11:00');
        $this->appointment($rebooked, '2026-10-04 11:00');

        $result = app(FollowUpSms::class)->missedAppointments();

        $this->assertSame('Missed', $missed->fresh()->status);
        $this->assertNotNull($missed->fresh()->missed_followup_sent_at);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $this->texts('appointment_missed_auto'));
        $this->assertStringContainsString('Ama', SmsLog::where('template_key', 'appointment_missed_auto')->value('message'));

        app(FollowUpSms::class)->missedAppointments();
        $this->assertSame(1, $this->texts('appointment_missed_auto'), 'Sent once only.');
    }

    public function test_scheduled_follow_ups_wait_for_sending_hours(): void
    {
        $this->switchOn('appointment_missed_auto');
        $this->appointment($this->patient('Ama'), '2026-09-30 09:00');

        $this->travelTo(Carbon::parse('2026-10-01 06:30:00'));
        app(FollowUpSms::class)->missedAppointments();
        $this->assertSame(0, $this->texts('appointment_missed_auto'));

        $this->travelTo(Carbon::parse('2026-10-01 09:05:00'));
        app(FollowUpSms::class)->missedAppointments();
        $this->assertSame(1, $this->texts('appointment_missed_auto'));
    }

    public function test_aftercare_goes_after_the_set_days_once_and_skips_old_collections(): void
    {
        $this->switchOn('aftercare_followup');
        Setting::getSettings()->update(['aftercare_sms_days' => 5]);
        $recent = $this->order('Collected', ['collected_at' => now()->subDays(5)->subHour()]);
        $tooSoon = $this->order('Collected', ['collected_at' => now()->subDays(2)]);
        $old = $this->order('Collected', ['collected_at' => now()->subDays(30)]);

        app(FollowUpSms::class)->aftercare();
        app(FollowUpSms::class)->aftercare();

        $this->assertSame(1, $this->texts('aftercare_followup'));
        $this->assertNotNull($recent->fresh()->aftercare_sent_at);
        $this->assertNull($tooSoon->fresh()->aftercare_sent_at);
        $this->assertNull($old->fresh()->aftercare_sent_at);
    }

    public function test_clinical_recall_goes_before_the_due_date_skips_booked_patients_and_resends_for_a_new_date(): void
    {
        $this->switchOn('clinical_recall');
        Setting::getSettings()->update(['clinical_recall_lead_days' => 7]);
        $due = $this->patient('Due Soon');
        $due->forceFill(['next_exam_due_on' => '2026-10-05'])->save();
        $later = $this->patient('Due Later', '0241115555');
        $later->forceFill(['next_exam_due_on' => '2026-12-01'])->save();
        $booked = $this->patient('Already Booked', '0241116666');
        $booked->forceFill(['next_exam_due_on' => '2026-10-04'])->save();
        $this->appointment($booked, '2026-10-03 10:00');

        app(FollowUpSms::class)->clinicalRecalls();
        app(FollowUpSms::class)->clinicalRecalls();

        $this->assertSame(1, $this->texts('clinical_recall'));
        $this->assertSame('2026-10-05', $due->fresh()->clinical_recall_sent_for->toDateString());
        $this->assertStringContainsString('Oct 05, 2026', SmsLog::where('template_key', 'clinical_recall')->value('message'));

        // The doctor sets a new due date: that one is announced too.
        $due->forceFill(['next_exam_due_on' => '2026-10-06'])->save();
        app(FollowUpSms::class)->clinicalRecalls();
        $this->assertSame(2, $this->texts('clinical_recall'));
    }

    public function test_order_delay_is_texted_only_for_a_new_date_on_an_open_order(): void
    {
        $this->switchOn('order_delay');
        $followUps = app(FollowUpSms::class);

        $pending = $this->order('Pending');
        $old = $pending->pickUpDate;
        $pending->update(['pickUpDate' => '2026-10-10']);
        $this->assertTrue($followUps->orderDateChanged($pending, $old));
        $this->assertStringContainsString('Sat 10 Oct 2026', SmsLog::where('template_key', 'order_delay')->value('message'));

        $this->assertFalse($followUps->orderDateChanged($pending, '2026-10-10'), 'Same date: nothing to say.');
        $ready = $this->order('Ready');
        $ready->update(['pickUpDate' => '2026-10-12']);
        $this->assertFalse($followUps->orderDateChanged($ready, '2026-10-03'), 'Already ready.');
        $this->assertSame(1, $this->texts('order_delay'));
    }

    public function test_staff_send_the_missed_follow_up_to_several_patients_after_a_confirm_step(): void
    {
        $ama = $this->appointment($this->patient('Ama'), '2026-09-29 09:00', 'Missed');
        $kofi = $this->appointment($this->patient('Kofi', '0241113333'), '2026-09-28 10:00', 'Missed');
        $noPhone = $this->appointment($this->patient('No Phone', ''), '2026-09-28 11:00', 'Missed');
        $done = $this->appointment($this->patient('Done Already', '0241114444'), '2026-09-27 11:00', 'Missed');
        $done->forceFill(['missed_followup_sent_at' => now()->subDay()])->save();
        $quiet = $this->appointment($this->patient('Quiet', '0241115555'), '2026-09-27 12:00', 'Missed');
        $quiet->update(['reminder_channel' => 'none']);

        $screen = $this->appointmentsScreen()->set('activeFilter', 'missed')
            ->set('selectedAppointments', array_map('strval', [$ama->id, $kofi->id, $noPhone->id, $done->id, $quiet->id]))
            ->call('prepareBulkFollowUp');

        $plan = $screen->get('bulkFollowUpPlan');
        $this->assertSame(['Kofi', 'Ama'], array_column($plan['send'], 'name'));
        // Listed oldest appointment first, each with its reason.
        $this->assertSame(['already followed up on Sep 30', 'asked for no messages', 'no phone number'], array_column($plan['skipped'], 'reason'));
        $this->assertSame(0, $this->texts('appointment_missed_followup'), 'Nothing is sent before confirming.');

        $screen->call('sendBulkFollowUp')->assertSet('bulkFollowUpPlan', null)->assertSet('selectedAppointments', []);
        $this->assertSame(2, $this->texts('appointment_missed_followup'));
        $this->assertNotNull($ama->fresh()->missed_followup_sent_at);
        $this->assertNotNull($kofi->fresh()->missed_followup_sent_at);

        // Sent once: picking them again skips them, and the quick filter hides followed-up rows.
        $this->assertSame([], $screen->set('selectedAppointments', [(string) $ama->id])->call('prepareBulkFollowUp')->get('bulkFollowUpPlan')['send']);
        $screen->set('missedView', 'not_followed');
        $this->assertSame([$noPhone->id, $quiet->id], collect($screen->viewData('appointments')->items())->pluck('id')->sort()->values()->all());
    }

    public function test_bulk_follow_up_stops_at_the_credits_left(): void
    {
        app(SmsCreditService::class)->add(app(TenantContext::class)->clinicId(), -99, 'adjustment', ['note' => 'Leave one credit']);
        $first = $this->appointment($this->patient('First'), '2026-09-29 09:00', 'Missed');
        $second = $this->appointment($this->patient('Second', '0241113333'), '2026-09-29 10:00', 'Missed');

        $plan = $this->appointmentsScreen()->set('activeFilter', 'missed')
            ->set('selectedAppointments', [(string) $first->id, (string) $second->id])
            ->call('prepareBulkFollowUp')->get('bulkFollowUpPlan');

        $this->assertCount(1, $plan['send']);
        $this->assertSame(1, $plan['leftOver']);
    }

    public function test_doctor_sets_the_next_exam_due_date_on_the_patient_record(): void
    {
        $patient = $this->patient('Ama');
        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $this->user->id, 'patient_id' => $patient->id,
            'payment_status' => 'Paid', 'doctor_status' => false, 'clearance_date' => now()->toDateString()]);

        $records = Livewire::test(\App\Livewire\Doctor\PatientRecordsComponent::class, ['clearance' => $clearance])
            ->call('setNextExamIn', 12)->assertHasNoErrors();
        $this->assertSame('2027-10-01', $patient->fresh()->next_exam_due_on->toDateString());

        $records->set('nextExamDueOn', '2026-09-01')->call('saveNextExamDue')->assertHasErrors('nextExamDueOn');
        $this->assertSame('2027-10-01', $patient->fresh()->next_exam_due_on->toDateString());

        $records->call('clearNextExamDue');
        $this->assertNull($patient->fresh()->next_exam_due_on);
    }

    /** The appointments screen, with the clinic's reschedule/cancel messages switched on (they start off). */
    private function appointmentsScreen(bool $noticesOn = true)
    {
        // The screen lists the clinic's doctors.
        \Spatie\Permission\Models\Role::findOrCreate('Doctor', 'web');
        if ($noticesOn) $this->switchOn('appointment_rescheduled', 'appointment_cancelled');

        return Livewire::test(\App\Livewire\Secretary\AppointmentsComponent::class);
    }

    public function test_patient_is_texted_when_an_upcoming_appointment_moves_and_the_reminder_rearms(): void
    {
        $appointment = $this->appointment($this->patient('Ama'), '2026-10-03 09:00');
        $appointment->forceFill(['reminder_sent_at' => now(), 'reminder_status' => 'sent'])->save();

        $this->appointmentsScreen()->call('dragDropReschedule', $appointment->id, '2026-10-06');

        $this->assertSame(1, $this->texts('appointment_rescheduled'));
        $this->assertStringContainsString('Oct 06, 2026 at 09:00 AM', SmsLog::where('template_key', 'appointment_rescheduled')->value('message'));
        $this->assertNull($appointment->fresh()->reminder_sent_at, 'The 24-hour reminder goes again for the new date.');
    }

    public function test_whatsapp_bookings_get_a_whatsapp_offer_instead_of_an_sms(): void
    {
        $appointment = $this->appointment($this->patient('Ama'), '2026-10-03 09:00');
        $appointment->update(['reminder_channel' => 'whatsapp']);

        $this->appointmentsScreen()->call('dragDropReschedule', $appointment->id, '2026-10-06')
            ->assertSet('confirmationKind', 'rescheduled')
            ->assertNotSet('confirmationWhatsAppUrl', null);

        $this->assertSame(0, $this->texts('appointment_rescheduled'));
    }

    public function test_patient_is_texted_when_the_clinic_cancels_an_upcoming_appointment_only(): void
    {
        $upcoming = $this->appointment($this->patient('Ama'), '2026-10-03 09:00');
        $past = $this->appointment($this->patient('Old', '0241117777'), '2026-09-01 09:00');

        $this->appointmentsScreen()->call('openCancelModal', $upcoming->id)->call('confirmCancelAppointment')
            ->call('updateStatus', $past->id, 'Cancelled');

        $this->assertSame('Cancelled', $upcoming->fresh()->status);
        $this->assertSame('Cancelled', $past->fresh()->status);
        $this->assertSame(1, $this->texts('appointment_cancelled'), 'Tidying up a past appointment texts nobody.');
    }

    public function test_switched_off_appointment_notices_are_not_sent(): void
    {
        $appointment = $this->appointment($this->patient('Ama'), '2026-10-03 09:00');

        $this->appointmentsScreen(noticesOn: false)->call('dragDropReschedule', $appointment->id, '2026-10-06')
            ->call('openCancelModal', $appointment->id)->call('confirmCancelAppointment');

        $this->assertSame(0, SmsLog::count());
    }

    public function test_balance_reminder_sends_one_total_per_person_on_schedule_up_to_the_maximum(): void
    {
        $this->switchOn('balance_reminder');
        Setting::getSettings()->update(['balance_reminder_first_days' => 3, 'balance_reminder_every_days' => 7, 'balance_reminder_max' => 2]);
        $ama = $this->patient('Ama');
        $context = app(TenantContext::class);
        foreach ([['B-1', 300, 100, 10], ['B-2', 50, 0, 5], ['TOO-NEW', 80, 0, 1]] as [$ref, $total, $paid, $daysAgo]) {
            \Illuminate\Support\Facades\DB::table('sales')->insert(['clinic_id' => $context->clinicId(), 'branch_id' => $context->branchId(),
                'patient_id' => $ama->id, 'transaction_id' => $ref, 'total_amount' => $total, 'amount_paid' => $paid,
                'payment_status' => 'partial', 'business_line' => 'clinic', 'is_refunded' => false,
                'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
        }
        $collected = $this->order('Collected', ['collected_at' => now()->subDays(4), 'lens_price' => 400, 'paid_amount' => 150]);

        app(FollowUpSms::class)->balanceReminders();
        $this->assertSame(2, $this->texts('balance_reminder'), 'Ama once (both her bills), the walk-in customer once.');
        $this->assertStringContainsString('250.00', SmsLog::where('template_key', 'balance_reminder')->where('patient_id', $ama->id)->value('message'));
        $this->assertSame(1, (int) $collected->fresh()->balance_reminders_sent);

        app(FollowUpSms::class)->balanceReminders();
        $this->assertSame(2, $this->texts('balance_reminder'), 'Not again within the week.');

        $this->travel(8)->days();
        app(FollowUpSms::class)->balanceReminders();
        $this->travel(8)->days();
        app(FollowUpSms::class)->balanceReminders();
        // The second round adds the bill that was too new, after which the maximum (2) is reached.
        $this->assertSame(2, (int) \App\Models\Sales::where('transaction_id', 'B-1')->value('balance_reminders_sent'));
        $this->assertSame(2, (int) \App\Models\Sales::where('transaction_id', 'TOO-NEW')->value('balance_reminders_sent'));
    }

    public function test_feedback_request_needs_the_review_link_and_goes_once_per_patient(): void
    {
        $this->switchOn('feedback_request');
        $ama = $this->patient('Ama');
        $clearance = \App\Models\CashierPatientClearance::create(['user_id' => $this->user->id, 'patient_id' => $ama->id,
            'payment_status' => 'Paid', 'doctor_status' => true, 'clearance_date' => now()->subDays(2)->toDateString()]);
        $visit = \App\Models\Consultations::create(['user_id' => $this->user->id, 'patient_id' => $ama->id, 'clearance_id' => $clearance->id, 'chiefComplaint' => 'Check-up']);
        $visit->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->order('Collected', ['collected_at' => now()->subDays(2), 'patient_id' => $ama->id]);

        app(FollowUpSms::class)->feedbackRequests();
        $this->assertSame(0, $this->texts('feedback_request'), 'No review link yet.');

        Setting::getSettings()->update(['review_link' => 'https://g.page/r/follow-clinic']);
        app(FollowUpSms::class)->feedbackRequests();
        app(FollowUpSms::class)->feedbackRequests();

        $this->assertSame(1, $this->texts('feedback_request'), 'Visit and collection the same week: asked once.');
        $this->assertStringContainsString('https://g.page/r/follow-clinic', SmsLog::where('template_key', 'feedback_request')->value('message'));
    }

    public function test_clinic_admin_sets_follow_up_timing(): void
    {
        $this->user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web'));

        Livewire::test(SmsTemplatesComponent::class)
            ->set('aftercareDays', 4)->set('recallLeadDays', 14)->call('saveFollowUpTiming')->assertHasNoErrors();

        $settings = Setting::getSettings();
        $this->assertSame(4, (int) $settings->aftercare_sms_days);
        $this->assertSame(14, (int) $settings->clinical_recall_lead_days);
    }
}
