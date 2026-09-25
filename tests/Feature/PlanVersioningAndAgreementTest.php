<?php
namespace Tests\Feature;
use App\Models\{Clinic,ClinicSubscription,SubscriptionPlan};
use App\Services\PlanVersioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class PlanVersioningAndAgreementTest extends TestCase
{
 use RefreshDatabase;
 public function test_plan_edit_creates_successor_without_changing_existing_contract():void{$clinic=Clinic::create(['name'=>'Version Clinic','slug'=>'version-clinic']);$plan=SubscriptionPlan::create(['name'=>'Basic','code'=>'basic','family_code'=>'basic','version'=>1,'base_price'=>100,'included_branches'=>1,'features'=>['appointments'],'billing_interval'=>'monthly','published_at'=>now()]);$subscription=ClinicSubscription::create(['clinic_id'=>$clinic->id,'subscription_plan_id'=>$plan->id,'status'=>'active','billing_interval'=>'monthly']);$successor=app(PlanVersioningService::class)->save($plan,['name'=>'Basic Plus','code'=>'basic','base_price'=>150,'included_branches'=>2,'features'=>['appointments','inventory'],'billing_interval'=>'monthly','is_active'=>true]);$this->assertFalse($plan->refresh()->is_active);$this->assertSame(2,$successor->version);$this->assertSame('basic',$successor->family_code);$this->assertSame($plan->id,$successor->supersedes_plan_id);$this->assertSame('100.00',$subscription->refresh()->pricing_snapshot['base_price']);$this->assertSame(1,$subscription->agreement->plan_version);}
 public function test_new_subscription_records_immutable_successor_agreement():void{$clinic=Clinic::create(['name'=>'Agreement Clinic','slug'=>'agreement-clinic']);$plan=SubscriptionPlan::create(['name'=>'Pro','code'=>'pro-v2','family_code'=>'pro','version'=>2,'terms_version'=>3,'terms'=>['notice_days'=>30],'base_price'=>250,'billing_interval'=>'monthly']);$subscription=ClinicSubscription::create(['clinic_id'=>$clinic->id,'subscription_plan_id'=>$plan->id,'status'=>'active']);$agreement=$subscription->agreement;$this->assertSame('pro',$agreement->plan_family_code);$this->assertSame(2,$agreement->plan_version);$this->assertSame(3,$agreement->terms_version);$this->assertSame(30,$agreement->terms_snapshot['notice_days']);}
}
