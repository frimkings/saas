<?php
namespace Tests\Feature;
use App\Models\{Clinic,ClinicSubscription,PlatformInvoice,SubscriptionPlan};
use App\Services\{SubscriptionBillingService,SubscriptionCollectionService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class SubscriptionCollectionsTest extends TestCase
{
 use RefreshDatabase;
 private function records():array{$clinic=Clinic::create(['name'=>'Collection Clinic','slug'=>'collection-clinic']);$plan=SubscriptionPlan::create(['name'=>'Collection Plan','code'=>'collection-plan','base_price'=>100,'billing_interval'=>'monthly']);$subscription=ClinicSubscription::create(['clinic_id'=>$clinic->id,'subscription_plan_id'=>$plan->id,'status'=>'overdue','billing_interval'=>'monthly','current_period_starts_at'=>now()->subMonths(2),'current_period_ends_at'=>now()->subMonth()]);$invoice=PlatformInvoice::create(['number'=>'INV-COLLECT','clinic_id'=>$clinic->id,'clinic_subscription_id'=>$subscription->id,'period_start'=>now()->subMonths(2),'period_end'=>now()->subMonth(),'due_date'=>now()->subDays(40),'subtotal'=>100,'tax'=>0,'total'=>100,'amount_paid'=>0,'currency'=>'GHS','status'=>'unpaid','source'=>'renewal']);return compact('clinic','subscription','invoice');}
 public function test_overdue_invoice_opens_aged_case_and_records_promise():void{$r=$this->records();$service=app(SubscriptionCollectionService::class);$result=$service->synchronize();$this->assertSame(1,$result['opened']);$case=\App\Models\SubscriptionCollectionCase::first();$this->assertSame('31-60',$case->aging_bucket);$service->promise($case,now()->addDays(5)->toDateString(),50,'Customer committed by phone',null);$this->assertSame('promise',$case->refresh()->status);$this->assertDatabaseCount('subscription_collection_notes',1);}
 public function test_full_manual_payment_resolves_collection_case():void{$r=$this->records();$service=app(SubscriptionCollectionService::class);$service->synchronize();app(SubscriptionBillingService::class)->allocatePayment($r['invoice'],100,'bank_transfer','COLLECT-1',null,'manual:COLLECT-1');$this->assertDatabaseHas('subscription_collection_cases',['platform_invoice_id'=>$r['invoice']->id,'status'=>'resolved']);}
 public function test_delinquent_subscription_can_be_manually_reinstated():void{$r=$this->records();app(SubscriptionCollectionService::class)->reinstate($r['subscription'],'Approved manual renewal');$this->assertSame('active',$r['subscription']->refresh()->status);$this->assertTrue($r['subscription']->current_period_ends_at->isFuture());}
}
