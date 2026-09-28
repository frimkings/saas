<?php

namespace App\Livewire\Admin;

use App\Models\{PatientDocument,SubscriptionChangeRequest,SubscriptionPlan};
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;

class ClinicSubscriptionPortalComponent extends Component
{
    public ?int $requestedPlanId=null;
    public string $requestedInterval='monthly';
    public string $requestMessage='';
    public string $billingLegalName='',$billingTaxId='',$billingAddress='',$billingEmail='',$billingPhone='';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Super Admin'),403);
        app(TenantContext::class)->ensureFor(auth()->user());
        $clinic=app(TenantContext::class)->requireClinic();$this->billingLegalName=$clinic->billing_legal_name??$clinic->name;$this->billingTaxId=$clinic->billing_tax_id??'';$this->billingAddress=$clinic->billing_address??'';$this->billingEmail=$clinic->billing_email??'';$this->billingPhone=$clinic->billing_phone??'';
    }

    public function saveBillingProfile():void{$data=$this->validate(['billingLegalName'=>'required|max:255','billingTaxId'=>'nullable|max:80','billingAddress'=>'required|max:1000','billingEmail'=>'required|email|max:255','billingPhone'=>'nullable|max:40']);app(TenantContext::class)->requireClinic()->update(['billing_legal_name'=>$data['billingLegalName'],'billing_tax_id'=>$data['billingTaxId']?:null,'billing_address'=>$data['billingAddress'],'billing_email'=>$data['billingEmail'],'billing_phone'=>$data['billingPhone']?:null]);session()->flash('subscription_message','Billing profile updated.');}

    public function requestPlanChange(): void
    {
        $data=$this->validate(['requestedPlanId'=>'required|exists:subscription_plans,id','requestedInterval'=>'required|in:monthly,yearly','requestMessage'=>'nullable|max:1000']);
        $clinic=app(TenantContext::class)->requireClinic();$subscription=app(SubscriptionService::class)->current($clinic);
        abort_if(SubscriptionChangeRequest::where('clinic_id',$clinic->id)->where('status','pending')->exists(),422,'A subscription change request is already awaiting review.');
        $request=SubscriptionChangeRequest::create(['clinic_id'=>$clinic->id,'current_subscription_id'=>$subscription?->id,'requested_plan_id'=>$data['requestedPlanId'],'billing_interval'=>$data['requestedInterval'],'message'=>$data['requestMessage']?:null,'requested_by'=>auth()->id()]);
        app(\App\Services\PlatformRequestAlerts::class)->planChangeRequested($request);
        $this->reset(['requestedPlanId','requestMessage']);session()->flash('subscription_message','Your request was submitted to the platform administrator.');
    }

    public function render()
    {
        $clinic=app(TenantContext::class)->requireClinic();$subscription=app(SubscriptionService::class)->current($clinic);
        $storageBytes=PatientDocument::query()->where('clinic_id',$clinic->id)->sum('file_size');
        return view('livewire.admin.clinic-subscription-portal-component',[
            'clinic'=>$clinic,'subscription'=>$subscription,'plans'=>SubscriptionPlan::where('is_active',true)->orderBy('base_price')->get(),
            'invoices'=>$clinic->invoices()->with('payments')->latest()->get(),
            'requests'=>SubscriptionChangeRequest::with('requestedPlan')->where('clinic_id',$clinic->id)->latest()->get(),
            'agreements'=>$clinic->subscriptions()->with(['agreement','plan'])->latest()->get()->pluck('agreement')->filter(),
            'usage'=>[
                'branches'=>$clinic->branches()->where('is_active',true)->count(),
                'users'=>$clinic->users()->wherePivot('status','active')->count(),
                'storage_mb'=>round($storageBytes/1048576,2),
            ],
            'smsCredits'=>app(\App\Services\Messaging\SmsCreditService::class)->balance($clinic->id),
        ])->layout('layouts.admin.admin-layout');
    }
}
