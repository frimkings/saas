<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClinicSubscriptionPortalComponent;
use App\Models\{Clinic,ClinicSubscription,PlatformInvoice,SubscriptionChangeRequest,SubscriptionPlan,User};
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClinicSubscriptionPortalTest extends TestCase
{
    use RefreshDatabase;

    private function setupClinic(string $slug='portal'): array
    {
        $role=Role::firstOrCreate(['name'=>'Super Admin','guard_name'=>'web']);$user=User::factory()->create();$user->assignRole($role);
        $clinic=Clinic::create(['name'=>ucfirst($slug).' Clinic','slug'=>$slug]);$branch=$clinic->branches()->create(['code'=>'MAIN','name'=>'Main','is_default'=>true,'is_active'=>true]);
        $clinic->users()->attach($user->id,['status'=>'active','is_default'=>true]);$branch->users()->attach($user->id,['status'=>'active','is_default'=>true]);DB::table('branch_user_role')->insert(['branch_id'=>$branch->id,'user_id'=>$user->id,'role_id'=>$role->id,'created_at'=>now(),'updated_at'=>now()]);
        $plan=SubscriptionPlan::create(['name'=>'Basic '.$slug,'code'=>'basic-'.$slug,'included_branches'=>1,'included_users'=>5,'base_price'=>99,'billing_interval'=>'monthly']);
        $subscription=ClinicSubscription::create(['clinic_id'=>$clinic->id,'subscription_plan_id'=>$plan->id,'status'=>'active','current_period_starts_at'=>now(),'current_period_ends_at'=>now()->addMonth()]);
        return compact('user','clinic','branch','plan','subscription');
    }

    public function test_super_admin_can_view_portal_and_submit_one_scoped_change_request(): void
    {
        config(['tenancy.enabled'=>true]);$data=$this->setupClinic();$upgrade=SubscriptionPlan::create(['name'=>'Pro','code'=>'pro','base_price'=>199,'billing_interval'=>'monthly']);
        app(TenantContext::class)->set($data['user'],$data['clinic'],$data['branch'],[$data['branch']->id]);
        Livewire::actingAs($data['user'])->test(ClinicSubscriptionPortalComponent::class)->assertSee('Subscription & Billing')->set('requestedPlanId',$upgrade->id)->set('requestMessage','Please upgrade us')->call('requestPlanChange')->assertHasNoErrors();
        $this->assertDatabaseHas('subscription_change_requests',['clinic_id'=>$data['clinic']->id,'requested_plan_id'=>$upgrade->id,'status'=>'pending']);
    }

    public function test_invoice_download_does_not_allow_cross_clinic_access(): void
    {
        config(['tenancy.enabled'=>true]);$first=$this->setupClinic('first');$second=$this->setupClinic('second');
        $invoice=PlatformInvoice::create(['number'=>'INV-CROSS','clinic_id'=>$second['clinic']->id,'clinic_subscription_id'=>$second['subscription']->id,'period_start'=>now(),'period_end'=>now()->addMonth(),'due_date'=>now()->addWeek(),'subtotal'=>10,'tax'=>0,'total'=>10,'amount_paid'=>0,'currency'=>'GHS','status'=>'unpaid']);
        app(TenantContext::class)->set($first['user'],$first['clinic'],$first['branch'],[$first['branch']->id]);
        $this->actingAs($first['user'])->get(route('admin.subscription.invoice',$invoice))->assertNotFound();
    }
}
