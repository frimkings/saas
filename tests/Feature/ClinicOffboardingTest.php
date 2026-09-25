<?php
namespace Tests\Feature;
use App\Models\{Clinic,ClinicSubscription,PlatformInvoice,SubscriptionPlan,User};
use App\Services\ClinicOffboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class ClinicOffboardingTest extends TestCase
{
 use RefreshDatabase;
 private function clinic():Clinic{$c=Clinic::create(['name'=>'Leaving Clinic','slug'=>'leaving-'.uniqid(),'status'=>'active']);$p=SubscriptionPlan::create(['name'=>'Exit Plan','code'=>'exit-'.uniqid(),'included_branches'=>1,'base_price'=>10,'billing_interval'=>'monthly']);ClinicSubscription::create(['clinic_id'=>$c->id,'subscription_plan_id'=>$p->id,'status'=>'active','billing_interval'=>'monthly','current_period_starts_at'=>now(),'current_period_ends_at'=>now()->addMonth()]);return $c;}
 public function test_immediate_cancellation_requires_a_different_approver_and_can_be_reversed():void
 {Storage::fake('local');$clinic=$this->clinic();$maker=User::factory()->create();$checker=User::factory()->create();$service=app(ClinicOffboardingService::class);$request=$service->request($clinic,'immediate','Clinic requested contract cancellation',$maker->id);$this->assertSame('pending_approval',$request->status);try{$service->approve($request,$maker->id,'Self approval');$this->fail('Self approval was allowed.');}catch(ValidationException){}$request=$service->approve($request->refresh(),$checker->id,'Contract and balances verified');$this->assertSame('retention',$request->status);$this->assertSame('cancelled',$clinic->currentSubscription->refresh()->status);$this->assertSame('suspended',$clinic->refresh()->status);Storage::disk('local')->assertExists($request->export_path);$this->assertSame(hash('sha256',Storage::disk('local')->get($request->export_path)),$request->export_checksum);$service->reverse($request,$checker->id,'Customer rescinded cancellation');$this->assertSame('active',$clinic->refresh()->status);$this->assertSame('active',$clinic->currentSubscription->refresh()->status);}
 public function test_outstanding_balance_blocks_offboarding():void
 {$clinic=$this->clinic();$maker=User::factory()->create();$checker=User::factory()->create();PlatformInvoice::create(['number'=>'INV-BLOCK-'.uniqid(),'clinic_id'=>$clinic->id,'clinic_subscription_id'=>$clinic->currentSubscription->id,'period_start'=>now(),'period_end'=>now()->addMonth(),'due_date'=>now(),'subtotal'=>50,'tax'=>0,'total'=>50,'amount_paid'=>0,'currency'=>'GHS','status'=>'unpaid']);$request=app(ClinicOffboardingService::class)->request($clinic,'immediate','Closure with unpaid invoice',$maker->id);$request=app(ClinicOffboardingService::class)->approve($request,$checker->id,'Reviewed outstanding account');$this->assertSame('blocked',$request->status);$this->assertSame('OUTSTANDING_BALANCE',$request->blockers[0]['code']);$this->assertSame('active',$clinic->refresh()->status);}
}
