<?php
namespace Tests\Feature;
use App\Mail\SubscriptionBillingMail;
use App\Models\{Clinic,ClinicSubscription,SubscriptionPlan,User};
use App\Services\{BillingNotificationService,SubscriptionBillingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Mail};
use Spatie\Permission\Models\Role;
use Tests\TestCase;
class SubscriptionBillingNotificationTest extends TestCase
{
 use RefreshDatabase;
 private function tenant(string $status='trial'):array{$role=Role::firstOrCreate(['name'=>'Super Admin','guard_name'=>'web']);$admin=User::factory()->create();$admin->assignRole($role);$clinic=Clinic::create(['name'=>'Notice Clinic','slug'=>'notice-clinic','status'=>'active','billing_email'=>$admin->email]);$branch=$clinic->branches()->create(['code'=>'MAIN','name'=>'Main','is_default'=>true,'is_active'=>true]);$clinic->users()->attach($admin->id,['status'=>'active','is_default'=>true,'clinic_role'=>'Super Admin']);$branch->users()->attach($admin->id,['status'=>'active','is_default'=>true]);DB::table('branch_user_role')->insert(['branch_id'=>$branch->id,'user_id'=>$admin->id,'role_id'=>$role->id,'created_at'=>now(),'updated_at'=>now()]);$plan=SubscriptionPlan::create(['name'=>'Notice Plan','code'=>'notice-plan','base_price'=>100,'billing_interval'=>'monthly']);$subscription=ClinicSubscription::create(['clinic_id'=>$clinic->id,'subscription_plan_id'=>$plan->id,'status'=>$status,'billing_interval'=>'monthly','trial_ends_at'=>now()->addDays(7),'current_period_starts_at'=>now(),'current_period_ends_at'=>now()->addDays(7)]);return compact('admin','clinic','subscription');}
 public function test_lifecycle_notice_is_emailed_once_to_the_owner_only():void{Mail::fake();$data=$this->tenant();$data['clinic']->update(['billing_email'=>'owner@notice.test']);$service=app(BillingNotificationService::class);$this->assertSame(1,$service->processDue());$this->assertSame(0,$service->processDue());$this->assertDatabaseCount('billing_notification_logs',1);$this->assertDatabaseCount('app_notifications',1);Mail::assertSent(SubscriptionBillingMail::class,1);Mail::assertSent(SubscriptionBillingMail::class,fn($mail)=>$mail->hasTo('owner@notice.test')&&!$mail->hasTo($data['admin']->email));}
 public function test_invoice_and_manual_payment_generate_immediate_notices():void{Mail::fake();$data=$this->tenant('active');$billing=app(SubscriptionBillingService::class);$invoice=$billing->invoiceFor($data['subscription']);$billing->allocatePayment($invoice,(float)$invoice->total,'bank_transfer','MANUAL-1',$data['admin']->id,'manual:MANUAL-1');$this->assertDatabaseCount('billing_notification_logs',2);Mail::assertSent(SubscriptionBillingMail::class,2);}
}
