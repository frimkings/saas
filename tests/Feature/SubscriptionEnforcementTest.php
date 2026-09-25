<?php
namespace Tests\Feature;
use App\Http\Middleware\EnforceSubscriptionWriteAccess;
use App\Models\{Clinic,ClinicSubscription,SubscriptionPlan,User};
use App\Services\SubscriptionQuotaService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;
    private function tenant(array $limits=[],string $status='active'): array
    {
        config(['tenancy.enabled'=>true]);$user=User::factory()->create();$clinic=Clinic::create(['name'=>'Quota Clinic','slug'=>'quota-clinic']);$branch=$clinic->branches()->create(['code'=>'MAIN','name'=>'Main','is_default'=>true,'is_active'=>true]);$clinic->users()->attach($user->id,['status'=>'active','is_default'=>true]);$branch->users()->attach($user->id,['status'=>'active','is_default'=>true]);
        $plan=SubscriptionPlan::create(array_merge(['name'=>'Limited','code'=>'limited','included_branches'=>1,'included_users'=>1,'storage_limit_mb'=>1,'sms_allowance'=>0,'features'=>['appointments'],'base_price'=>10,'billing_interval'=>'monthly'],$limits));
        $subscription=ClinicSubscription::create(['clinic_id'=>$clinic->id,'subscription_plan_id'=>$plan->id,'status'=>$status,'current_period_starts_at'=>now(),'current_period_ends_at'=>now()->addMonth(),'restricted_at'=>$status==='restricted'?now():null]);app(TenantContext::class)->set($user,$clinic,$branch,[$branch->id]);$this->actingAs($user);return compact('user','clinic','subscription');
    }
    public function test_user_allowance_is_enforced(): void
    {
        $data=$this->tenant();$quota=app(SubscriptionQuotaService::class);
        foreach(['assertUserAvailable'] as $method){try{$quota->$method($data['clinic']);$this->fail('Expected quota validation failure.');}catch(ValidationException $e){$this->assertArrayHasKey('subscription',$e->errors());}}
    }
    public function test_restricted_subscription_blocks_writes(): void
    {
        $this->tenant(status:'restricted');$request=Request::create('/livewire/update','POST');$route=new Route(['POST'],'livewire/update',fn()=>null);$route->name('livewire.update');$request->setRouteResolver(fn()=>$route);
        $this->expectException(HttpException::class);app(EnforceSubscriptionWriteAccess::class)->handle($request,fn()=>response('ok'));
    }
    public function test_direct_premium_route_is_blocked_when_feature_is_absent(): void
    {
        $this->tenant();$request=Request::create('/admin/stock-movements','GET');$route=new Route(['GET'],'admin/stock-movements',fn()=>null);$route->name('admin.stock-movements');$request->setRouteResolver(fn()=>$route);
        $this->expectException(HttpException::class);app(EnforceSubscriptionWriteAccess::class)->handle($request,fn()=>response('ok'));
    }
}
