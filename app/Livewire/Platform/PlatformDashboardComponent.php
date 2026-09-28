<?php
namespace App\Livewire\Platform;

use App\Models\{BillingNotificationLog,Branch,Clinic,ClinicOffboardingRequest,ClinicSubscription,PlatformAuditLog,PlatformCreditNote,PlatformInvoice,PlatformPayment,PlatformPaymentRefund,SubscriptionApprovalRequest,SubscriptionChange,SubscriptionChangeRequest,SubscriptionCollectionCase,SubscriptionPlan,SubscriptionReconciliationRun,User};
use App\Services\{ClinicOffboardingService,LegacyClinicImportService,PlanVersioningService,PlatformAuditService,SubscriptionApprovalService,SubscriptionBillingService,SubscriptionCollectionService,SubscriptionReconciliationService};
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\{Component,WithPagination};
use Livewire\Attributes\{Computed,Url};
use Spatie\Permission\Models\Role;

class PlatformDashboardComponent extends Component
{
 use WithPagination; protected $paginationTheme='bootstrap';
 #[Url(history: true, except: 'dashboard')]
 public string $tab='dashboard';
 public string $search='',$planFilter='',$statusFilter='',$deploymentFilter='',$limitFilter='',$productFilter=''; public ?int $selectedClinicId=null;
    public string $deleteClinicConfirmation='';
    public bool $deleteClinicAccounts=false;
    // Clinic side panel
    public string $panelTab='overview';
    public array $clinicForm=[];
    public string $statusReason='';
    public ?int $panelPlanId=null;
    public string $panelInterval='monthly',$panelTiming='period_end',$panelReason='';
    public bool $renewPayNow=false;
    public string $renewMethod='mobile_money',$renewReference='';
    public ?int $panelInvoiceId=null;
    public string $panelPaymentAmount='',$panelPaymentMethod='mobile_money',$panelPaymentReference='';
    // Billing workspace
    public string $billingSection='invoices';
    public string $invoiceSearch='',$invoiceStatus='',$invoiceSource='',$invoiceFrom='',$invoiceTo='';
    public ?int $viewInvoiceId=null;
    public string $invoiceAction='',$voidReason='',$invoicePeriodStart='',$invoicePeriodEnd='';
    public bool $showNewInvoice=false;
    // Plan side panel
    public bool $showPlanPanel=false,$showArchivedPlans=false;
    public string $planPanelTab='details';
    // Onboarding modal
    public bool $showOnboarding=false,$slugEdited=false;
    public int $onboardingStep=1;
 public ?int $editingPlanId=null,$includedBranches=1,$includedUsers=null,$storageLimitMb=null; public string $planName='',$planCode='',$currency='GHS',$basePrice='0',$annualPrice='0',$additionalBranchPrice='0',$taxRate='0',$planProduct='both',$planProductFilter='';public array $planExtras=[];public bool $planAllExtras=false; public int $trialDays=30,$graceDays=0;
 public ?int $clinicId=null,$planId=null,$branchLimitOverride=null; public string $status='active',$renewalMode='manual',$subscriptionInterval='monthly',$subscriptionReason='',$subscriptionNotes='';
 public ?int $invoiceClinicId=null,$paymentInvoiceId=null; public string $invoiceAmount='',$invoiceDueDate='',$invoiceNotes='',$paymentAmount='',$paymentMethod='mobile_money',$paymentReference='';
 public ?int $adjustmentInvoiceId=null,$refundPaymentId=null; public string $adjustmentAmount='',$adjustmentReason='',$refundMethod='bank_transfer',$refundReference='';
 public ?int $collectionCaseId=null; public string $collectionMethod='phone',$collectionNote='',$promiseDate='',$promiseAmount='';
 public string $approvalReviewNotes='';
 public ?int $offboardingClinicId=null; public string $offboardingTiming='period_end',$offboardingReason='',$offboardingReviewNotes='';
 public ?int $changeClinicId=null,$changePlanId=null; public string $changeInterval='monthly',$changeTiming='period_end',$changeReason='';
 public string $newClinicProduct='both',$newClinicName='',$newClinicSlug='',$newClinicDomain='',$newClinicDeployment='hosted',$newClinicCurrency='GHS',$newClinicEmail='',$newClinicPhone='',$newBranchCode='MAIN',$newBranchName='Main Branch',$newBranchTimezone='UTC',$newAdminName='',$newAdminEmail='',$newAdminPhone='',$onboardingMessage=''; public ?int $newPlanId=null;

 public function mount(){if(!in_array($this->tab,['dashboard','clinics','plans','billing','onboarding','audit','licenses'],true))$this->tab='dashboard';} public function updatedSearch(){ $this->resetPage(); } public function resetFilters(){ $this->reset(['search','planFilter','statusFilter','deploymentFilter','limitFilter','productFilter']);$this->resetPage(); } public function setTab(string $v){abort_unless(in_array($v,['dashboard','clinics','plans','billing','onboarding','audit','licenses']),422);$this->tab=$v;$this->selectedClinicId=null;$this->resetPage();} public function selectClinic(int $id){$clinic=Clinic::with('currentSubscription')->findOrFail($id);$this->selectedClinicId=$clinic->id;
        $this->reset('deleteClinicConfirmation','deleteClinicAccounts','statusReason','panelPlanId','panelReason','renewPayNow','renewReference','panelInvoiceId','panelPaymentAmount','panelPaymentReference');
        $this->resetErrorBag();$this->panelTab='overview';
        $this->panelInterval=$clinic->currentSubscription?->billing_interval==='yearly'?'yearly':'monthly';
        $this->clinicForm=$clinic->only(['name','domain','billing_email','billing_phone','billing_legal_name','billing_tax_id','billing_address','default_timezone','default_currency']);}

    public function closeClinic(){$this->reset('selectedClinicId','clinicForm','deleteClinicConfirmation','deleteClinicAccounts','statusReason');$this->resetErrorBag();}

    public function setPanelTab(string $tab){abort_unless(in_array($tab,['overview','plan','billing','people','danger'],true),422);$this->panelTab=$tab;$this->resetErrorBag();}

    // The slug and deployment mode are identity fields and are not editable here.
    public function saveClinic(){
        $clinic=Clinic::findOrFail($this->selectedClinicId);
        $d=$this->validate([
            'clinicForm.name'=>'required|max:255',
            'clinicForm.domain'=>['nullable','max:255',Rule::unique('clinics','domain')->ignore($clinic->id)],
            'clinicForm.billing_email'=>'nullable|email|max:255','clinicForm.billing_phone'=>'nullable|max:40',
            'clinicForm.billing_legal_name'=>'nullable|max:255','clinicForm.billing_tax_id'=>'nullable|max:80','clinicForm.billing_address'=>'nullable|max:1000',
            'clinicForm.default_timezone'=>'required|timezone','clinicForm.default_currency'=>'required|size:3',
        ],[],['clinicForm.name'=>'clinic name','clinicForm.domain'=>'domain','clinicForm.billing_email'=>'billing email','clinicForm.default_timezone'=>'timezone','clinicForm.default_currency'=>'currency'])['clinicForm'];
        $d=array_map(fn($v)=>is_string($v)&&trim($v)===''?null:$v,$d);$d['default_currency']=strtoupper($d['default_currency']);
        $old=$clinic->only(array_keys($d));$clinic->update($d);
        $changed=array_keys(array_diff_assoc(array_map('strval',$d),array_map('strval',$old)));
        if($changed)app(PlatformAuditService::class)->record('CLINIC_UPDATED',$clinic->id,array_intersect_key($old,array_flip($changed)),array_intersect_key($d,array_flip($changed)));
        session()->flash('panel_message',$changed?'Clinic details saved.':'No changes to save.');
    }

    // Suspending locks every user out of the clinic, so it needs a written reason.
    public function changeClinicStatus(){
        $this->validate(['statusReason'=>'required|min:5|max:500'],[],['statusReason'=>'reason']);
        $clinic=Clinic::findOrFail($this->selectedClinicId);
        $this->toggleClinic($clinic->id,$this->statusReason);
        $this->reset('statusReason');
        session()->flash('panel_message',$clinic->fresh()->status==='active'?'Clinic reactivated.':'Clinic suspended. Its users can no longer sign in.');
    }

    public function panelChangePlan(){
        $d=$this->validate(['panelPlanId'=>'required|exists:subscription_plans,id','panelInterval'=>'required|in:monthly,yearly','panelTiming'=>'required|in:immediate,period_end','panelReason'=>'required|min:5|max:500'],[],['panelPlanId'=>'plan','panelReason'=>'reason']);
        $clinic=Clinic::findOrFail($this->selectedClinicId);$current=$clinic->currentSubscription;
        if(!$current){$this->addError('panelPlanId','This clinic has no subscription yet. Assign one from Subscription Plans first.');return;}
        $change=app(SubscriptionBillingService::class)->scheduleChange($current,SubscriptionPlan::findOrFail($d['panelPlanId']),$d['panelInterval'],$d['panelTiming'],$d['panelReason'],auth()->id());
        app(PlatformAuditService::class)->record('SUBSCRIPTION_CHANGE_SCHEDULED',$clinic->id,[],['change_id'=>$change->id,'to_plan_id'=>$d['panelPlanId'],'timing'=>$d['panelTiming']],$d['panelReason']);
        $this->reset('panelPlanId','panelReason');
        session()->flash('panel_message',$d['panelTiming']==='immediate'?'Plan changed.':'Plan change scheduled for the end of the current period.');
    }

    // Renewal goes through the normal billing flow: a renewal invoice, and optionally its payment,
    // which extends the subscription period when the invoice is fully paid.
    public function panelRenew(){
        $this->validate(['renewMethod'=>'required|in:cash,bank_transfer,mobile_money,card','renewReference'=>'nullable|max:100']);
        $clinic=Clinic::findOrFail($this->selectedClinicId);$current=$clinic->currentSubscription;
        if(!$current){$this->addError('renew','This clinic has no subscription to renew.');return;}
        $billing=app(SubscriptionBillingService::class);$invoice=$billing->invoiceFor($current);
        app(PlatformAuditService::class)->record('RENEWAL_INVOICE_ISSUED',$clinic->id,[],['invoice'=>$invoice->number,'total'=>$invoice->total]);
        if($this->renewPayNow&&$invoice->balance()>0){
            $payment=$billing->allocatePayment($invoice,$invoice->balance(),$this->renewMethod,$this->renewReference?:null,auth()->id(),'renewal:'.$invoice->id.':'.$invoice->balance());
            app(PlatformAuditService::class)->record('PAYMENT_ALLOCATED',$clinic->id,[],['invoice'=>$invoice->number,'payment_id'=>$payment->id,'amount'=>$payment->amount]);
            $ends=$clinic->fresh('currentSubscription')->currentSubscription?->current_period_ends_at;
            session()->flash('panel_message',"Renewed. Invoice {$invoice->number} paid".($ends?'; the subscription now runs to '.$ends->format('d M Y').'.':'.'));
        }else{
            session()->flash('panel_message',"Renewal invoice {$invoice->number} is ready ({$invoice->currency} ".number_format($invoice->balance(),2).' due). Recording its payment extends the subscription.');
        }
        $this->reset('renewPayNow','renewReference');
    }

    public function panelRecordPayment(){
        $d=$this->validate(['panelInvoiceId'=>'required|exists:platform_invoices,id','panelPaymentAmount'=>'required|numeric|min:.01','panelPaymentMethod'=>'required|in:cash,bank_transfer,mobile_money,card','panelPaymentReference'=>'nullable|max:100'],[],['panelInvoiceId'=>'invoice','panelPaymentAmount'=>'amount']);
        $invoice=PlatformInvoice::where('clinic_id',$this->selectedClinicId)->findOrFail($d['panelInvoiceId']);
        if((float)$d['panelPaymentAmount']>$invoice->balance()+0.001){$this->addError('panelPaymentAmount','The amount is more than the invoice balance ('.number_format($invoice->balance(),2).').');return;}
        $payment=app(SubscriptionBillingService::class)->allocatePayment($invoice,(float)$d['panelPaymentAmount'],$d['panelPaymentMethod'],$d['panelPaymentReference']?:null,auth()->id(),$d['panelPaymentReference']?'manual:'.$d['panelPaymentMethod'].':'.$d['panelPaymentReference']:null);
        app(PlatformAuditService::class)->record('PAYMENT_ALLOCATED',$invoice->clinic_id,[],['invoice'=>$invoice->number,'payment_id'=>$payment->id,'amount'=>$d['panelPaymentAmount']]);
        $this->reset('panelInvoiceId','panelPaymentAmount','panelPaymentReference');
        session()->flash('panel_message','Payment recorded against '.$invoice->number.'.');
    }

    public function selectPanelInvoice(int $id){$invoice=PlatformInvoice::where('clinic_id',$this->selectedClinicId)->findOrFail($id);$this->panelInvoiceId=$invoice->id;$this->panelPaymentAmount=number_format($invoice->balance(),2,'.','');}

    /** Clinics whose current subscription covers this product (legacy "everything" lists count as both). */
    private function whereProduct($query,string $product){
        return $query->whereHas('currentSubscription',fn($x)=>match($product){
            'optical'=>$x->whereJsonContains('feature_snapshot','optical')->whereJsonDoesntContain('feature_snapshot','clinical'),
            'clinic'=>$x->whereJsonContains('feature_snapshot','clinical')->whereJsonDoesntContain('feature_snapshot','optical'),
            default=>$x->where(fn($y)=>$y->where(fn($z)=>$z->whereJsonContains('feature_snapshot','clinical')->whereJsonContains('feature_snapshot','optical'))
                ->orWhere(fn($z)=>$z->whereJsonDoesntContain('feature_snapshot','clinical')->whereJsonDoesntContain('feature_snapshot','optical'))->orWhereNull('feature_snapshot')),
        });
    }

    public function openNewPlan(){
        $this->reset(['editingPlanId','planName','planCode','currency','includedBranches','includedUsers','storageLimitMb','basePrice','annualPrice','additionalBranchPrice','planExtras','planAllExtras','taxRate','trialDays']);$this->planProduct=\App\Support\PlanProduct::BOTH;
        $this->resetErrorBag();$this->planPanelTab='details';$this->showPlanPanel=true;
    }

    public function openPlan(int $id){
        $this->resetErrorBag();$this->editPlan($id);
        $this->reset(['clinicId','branchLimitOverride','subscriptionReason','subscriptionNotes']);$this->planId=$id;$this->status='active';
        $this->planPanelTab='details';$this->showPlanPanel=true;
    }

    public function closePlanPanel(){$this->showPlanPanel=false;$this->reset(['editingPlanId','planName','planCode','planProduct','planExtras','planAllExtras']);$this->resetErrorBag();}

    public function setPlanPanelTab(string $tab){abort_unless(in_array($tab,['details','clinics'],true),422);$this->planPanelTab=$tab;$this->resetErrorBag();}

    public function setBillingSection(string $section){abort_unless(in_array($section,['invoices','collections','approvals','operations','offboarding'],true),422);$this->billingSection=$section;$this->closeInvoice();$this->resetErrorBag();}
    public function updated($name){if(in_array($name,['invoiceSearch','invoiceStatus','invoiceSource','invoiceFrom','invoiceTo'],true))$this->resetPage('invoicesPage');if(in_array($name,['productFilter','planFilter','statusFilter','deploymentFilter','limitFilter'],true))$this->resetPage();}
    public function resetInvoiceFilters(){$this->reset(['invoiceSearch','invoiceStatus','invoiceSource','invoiceFrom','invoiceTo']);$this->resetPage('invoicesPage');}

    private function invoiceQuery()
    {
        return PlatformInvoice::with('clinic')
            ->when($this->invoiceSearch,fn($q)=>$q->where(fn($x)=>$x->where('number','like','%'.$this->invoiceSearch.'%')->orWhereHas('clinic',fn($c)=>$c->where('name','like','%'.$this->invoiceSearch.'%'))))
            ->when($this->invoiceStatus==='overdue',fn($q)=>$q->whereIn('status',['unpaid','partial'])->whereDate('due_date','<',today()))
            ->when($this->invoiceStatus&&$this->invoiceStatus!=='overdue',fn($q)=>$q->where('status',$this->invoiceStatus))
            ->when($this->invoiceSource==='manual',fn($q)=>$q->where(fn($x)=>$x->whereNull('source')->orWhere('source','manual')))
            ->when($this->invoiceSource&&$this->invoiceSource!=='manual',fn($q)=>$q->where('source',$this->invoiceSource))
            ->when($this->invoiceFrom,fn($q)=>$q->whereDate('created_at','>=',$this->invoiceFrom))
            ->when($this->invoiceTo,fn($q)=>$q->whereDate('created_at','<=',$this->invoiceTo))
            ->latest();
    }

    private function billingSummary(): array
    {
        $open=PlatformInvoice::whereIn('status',['unpaid','partial'])->whereNull('voided_at')->get();
        $monthStart=now()->startOfMonth();
        return [
            'outstanding'=>$open->sum(fn($i)=>$i->balance()),'outstanding_count'=>$open->count(),
            'overdue'=>$open->filter(fn($i)=>$i->due_date&&$i->due_date->lt(today()))->sum(fn($i)=>$i->balance()),'overdue_count'=>$open->filter(fn($i)=>$i->due_date&&$i->due_date->lt(today()))->count(),
            'collected'=>(float)PlatformPayment::where('status','confirmed')->where('paid_at','>=',$monthStart)->sum('amount'),
            'adjusted'=>(float)PlatformCreditNote::where('issued_at','>=',$monthStart)->sum('amount')+(float)PlatformPaymentRefund::where('refunded_at','>=',$monthStart)->sum('amount'),
        ];
    }

    public function openInvoice(int $id){$invoice=PlatformInvoice::findOrFail($id);$this->viewInvoiceId=$invoice->id;$this->showNewInvoice=false;$this->invoiceAction='';$this->resetInvoiceForms();$this->resetErrorBag();}
    public function closeInvoice(){$this->viewInvoiceId=null;$this->invoiceAction='';$this->resetInvoiceForms();$this->resetErrorBag();}
    private function resetInvoiceForms(){$this->reset(['paymentAmount','paymentReference','adjustmentAmount','adjustmentReason','refundPaymentId','refundReference','voidReason']);}

    public function startInvoiceAction(string $action,?int $paymentId=null){
        abort_unless(in_array($action,['pay','credit','refund','void'],true),422);
        $invoice=PlatformInvoice::findOrFail($this->viewInvoiceId);$this->resetInvoiceForms();$this->resetErrorBag();$this->invoiceAction=$action;
        if($action==='pay')$this->paymentAmount=number_format($invoice->balance(),2,'.','');
        if($action==='refund'){$payment=$invoice->payments()->findOrFail($paymentId);$this->refundPaymentId=$payment->id;$this->adjustmentAmount=number_format((float)$payment->amount,2,'.','');}
    }

    public function submitInvoicePayment(){
        $invoice=PlatformInvoice::findOrFail($this->viewInvoiceId);
        $this->validate(['paymentAmount'=>'required|numeric|min:.01'],[],['paymentAmount'=>'amount']);
        if((float)$this->paymentAmount>$invoice->balance()+0.001){$this->addError('paymentAmount','The amount is more than the balance ('.number_format($invoice->balance(),2).').');return;}
        $this->paymentInvoiceId=$invoice->id;$this->recordPayment();
        $this->invoiceAction='';session()->flash('billing_message','Payment recorded.');
    }
    public function submitInvoiceCredit(){$this->adjustmentInvoiceId=$this->viewInvoiceId;$this->issueCreditNote();$this->invoiceAction='';}
    public function submitInvoiceRefund(){$this->refundPayment();$this->invoiceAction='';}
    public function submitInvoiceVoid(){
        $this->validate(['voidReason'=>'required|min:5|max:500'],[],['voidReason'=>'reason']);
        $invoice=PlatformInvoice::findOrFail($this->viewInvoiceId);
        abort_if($invoice->voided_at||(float)$invoice->amount_paid>0||(float)$invoice->credited_amount>0,422,'Only an invoice with no payments or credits can be voided.');
        $this->voidInvoice($invoice->id,$this->voidReason);$this->invoiceAction='';$this->reset('voidReason');
    }

    public function openNewInvoice(?int $clinicId=null){
        $this->closeInvoice();$this->reset(['invoiceClinicId','invoiceAmount','invoiceNotes']);$this->invoiceClinicId=$clinicId;
        $this->invoiceDueDate=today()->addDays(7)->toDateString();$this->invoicePeriodStart=today()->toDateString();$this->invoicePeriodEnd=today()->addMonth()->subDay()->toDateString();
        $this->showNewInvoice=true;
    }
    public function closeNewInvoice(){$this->showNewInvoice=false;$this->resetErrorBag();}

    // Onboarding modal: three steps over the same fields and rules as onboardClinic().
    private const ONBOARDING_STEPS=[1=>['newClinicProduct','newClinicName','newClinicSlug','newClinicDomain','newClinicDeployment','newClinicEmail'],2=>['newBranchCode','newBranchName','newBranchTimezone'],3=>['newAdminName','newAdminEmail','newPlanId']];
    public function openOnboarding(){$this->resetErrorBag();$this->onboardingMessage='';$this->onboardingStep=1;$this->showOnboarding=true;}
    public function closeOnboarding(){$this->showOnboarding=false;$this->resetErrorBag();}
    public function onboardingNext(){$this->validate(array_intersect_key($this->onboardingRules(),array_flip(self::ONBOARDING_STEPS[$this->onboardingStep])));$this->onboardingStep=min(3,$this->onboardingStep+1);}
    public function onboardingBack(){$this->resetErrorBag();$this->onboardingStep=max(1,$this->onboardingStep-1);}
    // The slug follows the clinic name until the administrator edits it by hand.
    public function updatedNewClinicName($value){if(!$this->slugEdited)$this->newClinicSlug=Str::slug((string)$value);}
    /** A new product choice clears a plan picked for a different product. */
    public function updatedNewClinicProduct(){if($this->newPlanId&&SubscriptionPlan::whereKey($this->newPlanId)->value('product')!==$this->newClinicProduct)$this->newPlanId=null;}
    public function updatedNewClinicSlug(){$this->slugEdited=true;}
    private function onboardingRules(): array{return ['newClinicName'=>'required|max:255','newClinicSlug'=>'required|alpha_dash|unique:clinics,slug','newClinicDomain'=>'nullable|max:255|unique:clinics,domain','newClinicDeployment'=>'required|in:hosted,local','newClinicEmail'=>'nullable|email','newBranchCode'=>'required|max:30','newBranchName'=>'required|max:255','newBranchTimezone'=>'required|timezone','newAdminName'=>'required|max:255','newAdminEmail'=>'required|email|unique:users,email','newClinicProduct'=>['required',Rule::in(array_keys(\App\Support\PlanProduct::PRODUCTS))],'newPlanId'=>['required',Rule::exists('subscription_plans','id')->where('product',$this->newClinicProduct)->where('is_active',true)]];}

    // Permanently deletes an empty clinic (e.g. a duplicate created by hand). Clinics with data are refused by the service.
    public function deleteClinic(int $id,LegacyClinicImportService $service,PlatformAuditService $audit){
        $clinic=Clinic::findOrFail($id);
        if($this->deleteClinicConfirmation!=='DELETE '.$clinic->slug){$this->addError('deleteClinic','Type the exact confirmation phrase shown.');return;}
        if($blocker=$service->clinicDeletionBlocker($clinic->id)){$this->addError('deleteClinic',$blocker);return;}
        $accounts=$this->deleteClinicAccounts?$service->clinicOnlyAccounts($clinic->id)->pluck('id')->all():[];
        $summary=$service->deleteClinic($clinic->id,$accounts);
        $audit->record('clinic.deleted',null,['clinic_name'=>$clinic->name,'clinic_slug'=>$clinic->slug],['deleted'=>$summary['deleted'],'deleted_user_count'=>count($summary['deleted_user_ids'])]);
        $this->reset('selectedClinicId','deleteClinicConfirmation','deleteClinicAccounts');
        session()->flash('success',$clinic->name.' was permanently deleted.'.($summary['file_cleanup_errors']?' Some stored files could not be deleted and were logged.':''));
    }
 public function savePlan(){ $d=$this->validate(['planName'=>'required|max:100','planCode'=>['required','alpha_dash',Rule::unique('subscription_plans','code')->ignore($this->editingPlanId)],'includedBranches'=>'nullable|integer|min:1','includedUsers'=>'nullable|integer|min:1','storageLimitMb'=>'nullable|integer|min:1','basePrice'=>'required|numeric|min:0','annualPrice'=>'required|numeric|min:0','additionalBranchPrice'=>'required|numeric|min:0','currency'=>'required|size:3','trialDays'=>'required|integer|min:0','graceDays'=>'required|integer|min:0','taxRate'=>'required|numeric|min:0|max:100','planProduct'=>['required',Rule::in(array_keys(\App\Support\PlanProduct::PRODUCTS))],'planExtras'=>'array','planExtras.*'=>[Rule::in(array_keys(\App\Support\PlanProduct::EXTRAS))],'planAllExtras'=>'boolean'],[],['planProduct'=>'product']);$extras=collect($d['planExtras']??[])->filter(fn($k)=>(\App\Support\PlanProduct::EXTRAS[$k][1]??null)!==\App\Support\PlanProduct::CLINIC||$d['planProduct']!==\App\Support\PlanProduct::OPTICAL)->all();$current=$this->editingPlanId?SubscriptionPlan::findOrFail($this->editingPlanId):null;$old=$current?->toArray()??[];$attributes=['name'=>$d['planName'],'code'=>$d['planCode'],'included_branches'=>$d['includedBranches'],'included_users'=>$d['includedUsers'],'storage_limit_mb'=>$d['storageLimitMb'],'base_price'=>$d['basePrice'],'annual_price'=>$d['annualPrice'],'additional_branch_price'=>$d['additionalBranchPrice'],'currency'=>strtoupper($d['currency']),'trial_days'=>$d['trialDays'],'grace_days'=>0,'tax_rate'=>$d['taxRate'],'product'=>$d['planProduct'],'features'=>\App\Support\PlanProduct::features($d['planProduct'],$extras,(bool)$d['planAllExtras']),'is_active'=>true];$p=app(PlanVersioningService::class)->save($current,$attributes);app(PlatformAuditService::class)->record($current?'PLAN_VERSION_CREATED':'PLAN_CREATED',null,$old,$p->toArray());$this->reset(['editingPlanId','planName','planCode','planExtras','planAllExtras']);if($this->showPlanPanel){$this->openPlan($p->id);session()->flash('panel_message',$current?"Saved as version {$p->version} ({$p->code}). Clinics on earlier versions keep their current terms.":'Plan created.');} }
 public function editPlan(int $id){$p=SubscriptionPlan::findOrFail($id);foreach(['id'=>'editingPlanId','name'=>'planName','code'=>'planCode','included_branches'=>'includedBranches','included_users'=>'includedUsers','storage_limit_mb'=>'storageLimitMb','base_price'=>'basePrice','annual_price'=>'annualPrice','additional_branch_price'=>'additionalBranchPrice','currency'=>'currency','trial_days'=>'trialDays','grace_days'=>'graceDays','tax_rate'=>'taxRate'] as $a=>$b)$this->$b=$p->$a;$this->planProduct=$p->product?:\App\Support\PlanProduct::productOf($p->features);$this->planAllExtras=empty($p->features)||in_array('*',$p->features??[],true);$this->planExtras=$this->planAllExtras?[]:\App\Support\PlanProduct::extrasOf($p->features);}
 public function duplicatePlan(int $id){$p=SubscriptionPlan::findOrFail($id);$n=$p->replicate(['supersedes_plan_id','published_at','retired_at']);$n->name.=' Copy';$n->code.='-'.Str::lower(Str::random(5));$n->family_code=$n->code;$n->version=1;$n->published_at=now();$n->save();app(PlatformAuditService::class)->record('PLAN_DUPLICATED',null,[],['source_id'=>$id,'new_id'=>$n->id]);} public function archivePlan(int $id){$p=SubscriptionPlan::findOrFail($id);$p->update(['is_active'=>false,'retired_at'=>now()]);app(PlatformAuditService::class)->record('PLAN_ARCHIVED',null,[],['plan_id'=>$id]);if($this->showPlanPanel&&$this->editingPlanId===$id)session()->flash('panel_message','Plan archived. It is no longer offered; current subscribers are unaffected.');}
 public function assignSubscription(){ $d=$this->validate(['clinicId'=>'required|exists:clinics,id','planId'=>'required|exists:subscription_plans,id','status'=>'required|in:trial,active,overdue,suspended,cancelled','branchLimitOverride'=>'nullable|integer|min:1','renewalMode'=>'required|in:manual,automatic','subscriptionInterval'=>'required|in:monthly,yearly','subscriptionReason'=>'required|min:5|max:500','subscriptionNotes'=>'nullable|max:1000']);$c=Clinic::findOrFail($d['clinicId']);$old=$c->currentSubscription?->toArray()??[];$p=SubscriptionPlan::findOrFail($d['planId']);$s=ClinicSubscription::create(['clinic_id'=>$c->id,'subscription_plan_id'=>$p->id,'status'=>$d['status'],'branch_limit_override'=>$d['branchLimitOverride'],'renewal_mode'=>$d['renewalMode'],'billing_interval'=>$d['subscriptionInterval'],'notes'=>$d['subscriptionNotes'],'change_reason'=>$d['subscriptionReason'],'current_period_starts_at'=>now(),'current_period_ends_at'=>$d['subscriptionInterval']==='yearly'?now()->addYear():now()->addMonth(),'trial_ends_at'=>$d['status']==='trial'?now()->addDays($p->trial_days):null,'grace_ends_at'=>$d['status']==='overdue'?now()->addDays($p->grace_days):null,'cancelled_at'=>$d['status']==='cancelled'?now():null]);app(PlatformAuditService::class)->record('SUBSCRIPTION_CHANGED',$c->id,$old,$s->toArray(),$d['subscriptionReason']);$this->reset(['clinicId','branchLimitOverride','subscriptionReason','subscriptionNotes']);if($this->showPlanPanel)session()->flash('panel_message',$c->name.' is now on '.$p->name.'.');else $this->reset('planId');}
 public function toggleClinic(int $id,string $reason){abort_if(strlen(trim($reason))<5,422);$c=Clinic::findOrFail($id);$old=$c->status;$c->update(['status'=>$old==='active'?'suspended':'active']);app(PlatformAuditService::class)->record('CLINIC_STATUS_CHANGED',$id,['status'=>$old],['status'=>$c->status],$reason);}
 public function generateInvoice(){ $d=$this->validate(['invoiceClinicId'=>'required|exists:clinics,id','invoiceAmount'=>'required|numeric|min:.01','invoiceDueDate'=>'required|date','invoiceNotes'=>'nullable|max:1000','invoicePeriodStart'=>'nullable|date','invoicePeriodEnd'=>'nullable|date|after_or_equal:invoicePeriodStart'],[],['invoiceClinicId'=>'clinic','invoiceAmount'=>'amount','invoicePeriodEnd'=>'period end']);$c=Clinic::findOrFail($d['invoiceClinicId']);$s=$c->currentSubscription;$tax=round($d['invoiceAmount']*(($s?->plan?->tax_rate??0)/100),2);$i=PlatformInvoice::create(['number'=>'INV-'.now()->format('YmHis').'-'.Str::upper(Str::random(3)),'clinic_id'=>$c->id,'clinic_subscription_id'=>$s?->id,'period_start'=>$this->invoicePeriodStart?:now()->startOfMonth(),'period_end'=>$this->invoicePeriodEnd?:now()->endOfMonth(),'source'=>'manual','due_date'=>$d['invoiceDueDate'],'subtotal'=>$d['invoiceAmount'],'tax'=>$tax,'total'=>$d['invoiceAmount']+$tax,'currency'=>$s?->plan?->currency??'GHS','status'=>'unpaid','notes'=>$d['invoiceNotes']]);app(PlatformAuditService::class)->record('INVOICE_CREATED',$c->id,[],$i->toArray());$this->reset(['invoiceClinicId','invoiceAmount','invoiceDueDate','invoiceNotes','invoicePeriodStart','invoicePeriodEnd']);if($this->showNewInvoice){$this->openInvoice($i->id);session()->flash('billing_message','Invoice '.$i->number.' created.');}}
 public function recordPayment(){ $d=$this->validate(['paymentInvoiceId'=>'required|exists:platform_invoices,id','paymentAmount'=>'required|numeric|min:.01','paymentMethod'=>'required|in:cash,bank_transfer,mobile_money,card','paymentReference'=>'nullable|max:100']);$i=PlatformInvoice::findOrFail($d['paymentInvoiceId']);$payment=app(SubscriptionBillingService::class)->allocatePayment($i,(float)$d['paymentAmount'],$d['paymentMethod'],$d['paymentReference']?:null,auth()->id(),$d['paymentReference']?'manual:'.$d['paymentMethod'].':'.$d['paymentReference']:null);app(PlatformAuditService::class)->record('PAYMENT_ALLOCATED',$i->clinic_id,[],['invoice'=>$i->number,'payment_id'=>$payment->id,'amount'=>$d['paymentAmount']]);$this->reset(['paymentInvoiceId','paymentAmount','paymentReference']);}
 public function runAutomatedBilling(){ $service=app(SubscriptionBillingService::class);$changes=$service->applyScheduledChanges();$invoices=$service->generateDueInvoices();app(PlatformAuditService::class)->record('AUTOMATED_BILLING_RUN',null,[],compact('changes','invoices'));session()->flash('billing_message',"Billing processed: {$invoices} invoice(s), {$changes} plan change(s).");}
 public function schedulePlanChange(){ $d=$this->validate(['changeClinicId'=>'required|exists:clinics,id','changePlanId'=>'required|exists:subscription_plans,id','changeInterval'=>'required|in:monthly,yearly','changeTiming'=>'required|in:immediate,period_end','changeReason'=>'required|min:5|max:500']);$clinic=Clinic::findOrFail($d['changeClinicId']);$current=$clinic->currentSubscription;abort_unless($current,422,'Clinic has no current subscription.');$change=app(SubscriptionBillingService::class)->scheduleChange($current,SubscriptionPlan::findOrFail($d['changePlanId']),$d['changeInterval'],$d['changeTiming'],$d['changeReason'],auth()->id());app(PlatformAuditService::class)->record('SUBSCRIPTION_CHANGE_SCHEDULED',$clinic->id,[],['change_id'=>$change->id,'to_plan_id'=>$d['changePlanId'],'timing'=>$d['changeTiming']],$d['changeReason']);$this->reset(['changeClinicId','changePlanId','changeReason']);}
 public function reviewPlanRequest(int $id,bool $approve){$request=SubscriptionChangeRequest::with(['currentSubscription','requestedPlan'])->findOrFail($id);abort_unless($request->status==='pending',422,'Request was already reviewed.');if($approve){abort_unless($request->currentSubscription,422,'The clinic no longer has that subscription.');app(SubscriptionBillingService::class)->scheduleChange($request->currentSubscription,$request->requestedPlan,$request->billing_interval,'period_end','Approved clinic request #'.$request->id,auth()->id());}$request->update(['status'=>$approve?'approved':'rejected','reviewed_by'=>auth()->id(),'reviewed_at'=>now(),'review_notes'=>$approve?'Scheduled for period end.':'Rejected by platform administrator.']);app(PlatformAuditService::class)->record($approve?'SUBSCRIPTION_REQUEST_APPROVED':'SUBSCRIPTION_REQUEST_REJECTED',$request->clinic_id,[],['request_id'=>$request->id]);app(\App\Services\OwnerAlerts::class)->planRequestReviewed($request);}
 public function issueCreditNote(){ $d=$this->validate(['adjustmentInvoiceId'=>'required|exists:platform_invoices,id','adjustmentAmount'=>'required|numeric|min:.01','adjustmentReason'=>'required|min:5|max:1000']);$invoice=PlatformInvoice::findOrFail($d['adjustmentInvoiceId']);$r=app(SubscriptionApprovalService::class)->request('credit_note',PlatformInvoice::class,$invoice->id,$invoice->clinic_id,['amount'=>(float)$d['adjustmentAmount']],$d['adjustmentReason'],auth()->id());app(PlatformAuditService::class)->record('CREDIT_NOTE_APPROVAL_REQUESTED',$invoice->clinic_id,[],['approval_id'=>$r->id,'amount'=>$d['adjustmentAmount']],$d['adjustmentReason']);$this->reset(['adjustmentInvoiceId','adjustmentAmount','adjustmentReason']);session()->flash('billing_message','Credit note submitted for independent approval.');}
 public function refundPayment(){ $d=$this->validate(['refundPaymentId'=>'required|exists:platform_payments,id','adjustmentAmount'=>'required|numeric|min:.01','refundMethod'=>'required|in:cash,bank_transfer,mobile_money,card','refundReference'=>'nullable|max:100','adjustmentReason'=>'required|min:5|max:1000']);$payment=PlatformPayment::with('invoice')->findOrFail($d['refundPaymentId']);$r=app(SubscriptionApprovalService::class)->request('payment_refund',PlatformPayment::class,$payment->id,$payment->invoice?->clinic_id,['amount'=>(float)$d['adjustmentAmount'],'method'=>$d['refundMethod'],'reference'=>$d['refundReference']?:null],$d['adjustmentReason'],auth()->id());app(PlatformAuditService::class)->record('PAYMENT_REFUND_APPROVAL_REQUESTED',$r->clinic_id,[],['approval_id'=>$r->id,'amount'=>$d['adjustmentAmount']],$d['adjustmentReason']);$this->reset(['refundPaymentId','adjustmentAmount','adjustmentReason','refundReference']);session()->flash('billing_message','Refund submitted for independent approval.');}
 public function voidInvoice(int $id,string $reason){abort_if(strlen(trim($reason))<5,422,'A reason is required.');$invoice=PlatformInvoice::findOrFail($id);$r=app(SubscriptionApprovalService::class)->request('invoice_void',PlatformInvoice::class,$invoice->id,$invoice->clinic_id,[],$reason,auth()->id());app(PlatformAuditService::class)->record('INVOICE_VOID_APPROVAL_REQUESTED',$invoice->clinic_id,[],['approval_id'=>$r->id],$reason);session()->flash('billing_message','Invoice void submitted for independent approval.');}
 public function syncCollections(){ $r=app(SubscriptionCollectionService::class)->synchronize();session()->flash('billing_message',"Collections synchronized: {$r['opened']} opened, {$r['resolved']} resolved.");}
 public function bulkGenerateRenewals(){ $count=app(SubscriptionCollectionService::class)->bulkGenerate();session()->flash('billing_message',"Generated {$count} new renewal invoice(s).");}
 public function addCollectionNote(){ $d=$this->validate(['collectionCaseId'=>'required|exists:subscription_collection_cases,id','collectionMethod'=>'required|in:phone,email,whatsapp,internal,promise','collectionNote'=>'required|min:3|max:1000']);$case=SubscriptionCollectionCase::findOrFail($d['collectionCaseId']);app(SubscriptionCollectionService::class)->note($case,$d['collectionMethod'],$d['collectionNote'],auth()->id());app(PlatformAuditService::class)->record('COLLECTION_CONTACT_RECORDED',$case->clinic_id,[],['case_id'=>$case->id,'method'=>$d['collectionMethod']]);$this->reset(['collectionCaseId','collectionNote']);}
 public function recordPaymentPromise(){ $d=$this->validate(['collectionCaseId'=>'required|exists:subscription_collection_cases,id','promiseDate'=>'required|date|after_or_equal:today','promiseAmount'=>'required|numeric|min:.01','collectionNote'=>'required|min:3|max:1000']);$case=SubscriptionCollectionCase::findOrFail($d['collectionCaseId']);app(SubscriptionCollectionService::class)->promise($case,$d['promiseDate'],(float)$d['promiseAmount'],$d['collectionNote'],auth()->id());app(PlatformAuditService::class)->record('PAYMENT_PROMISE_RECORDED',$case->clinic_id,[],['case_id'=>$case->id,'date'=>$d['promiseDate'],'amount'=>$d['promiseAmount']]);$this->reset(['collectionCaseId','promiseDate','promiseAmount','collectionNote']);}
 public function reinstateSubscription(int $id){$s=ClinicSubscription::findOrFail($id);$reason='Manual reinstatement after collection review';$r=app(SubscriptionApprovalService::class)->request('subscription_reinstate',ClinicSubscription::class,$s->id,$s->clinic_id,[],$reason,auth()->id());app(PlatformAuditService::class)->record('SUBSCRIPTION_REINSTATEMENT_REQUESTED',$s->clinic_id,[],['approval_id'=>$r->id],$reason);session()->flash('billing_message','Reinstatement submitted for independent approval.');}
 public function reviewApproval(int $id,bool $approve){$d=$this->validate(['approvalReviewNotes'=>'required|min:3|max:1000']);$request=SubscriptionApprovalRequest::findOrFail($id);$service=app(SubscriptionApprovalService::class);$reviewed=$approve?$service->approve($request,auth()->id(),$d['approvalReviewNotes']):$service->reject($request,auth()->id(),$d['approvalReviewNotes']);app(PlatformAuditService::class)->record($approve?'SUBSCRIPTION_ACTION_APPROVED':'SUBSCRIPTION_ACTION_REJECTED',$reviewed->clinic_id,[],['approval_id'=>$reviewed->id,'action'=>$reviewed->action],$d['approvalReviewNotes']);$this->reset('approvalReviewNotes');session()->flash('billing_message',$approve?'Approved action executed successfully.':'Approval request rejected.');}
 #[Computed] public function approvalRequests(){return SubscriptionApprovalRequest::with(['clinic','requester','reviewer'])->orderByRaw("FIELD(status,'pending','expired','rejected','approved')")->latest()->limit(100)->get();}
 public function runSubscriptionReconciliation(){ $run=app(SubscriptionReconciliationService::class)->run(auth()->id());app(PlatformAuditService::class)->record('SUBSCRIPTION_RECONCILIATION_RUN',null,[],['run_id'=>$run->id,'status'=>$run->status,'critical'=>$run->critical_issues,'warnings'=>$run->warning_issues]);session()->flash('billing_message',"Reconciliation {$run->status}: {$run->critical_issues} critical issue(s), {$run->warning_issues} warning(s).");}
 #[Computed] public function reconciliationRuns(){return SubscriptionReconciliationRun::with(['issues.clinic','operator'])->latest()->limit(10)->get();}
 public function requestOffboarding(){ $d=$this->validate(['offboardingClinicId'=>'required|exists:clinics,id','offboardingTiming'=>'required|in:period_end,immediate','offboardingReason'=>'required|min:10|max:1000']);$r=app(ClinicOffboardingService::class)->request(Clinic::findOrFail($d['offboardingClinicId']),$d['offboardingTiming'],$d['offboardingReason'],auth()->id());app(PlatformAuditService::class)->record('CLINIC_OFFBOARDING_REQUESTED',$r->clinic_id,[],['request_id'=>$r->id,'timing'=>$r->timing],$r->reason);$this->reset(['offboardingClinicId','offboardingReason']);session()->flash('billing_message','Clinic offboarding request created.');}
 public function approveOffboarding(int $id){$d=$this->validate(['offboardingReviewNotes'=>'required|min:3|max:1000']);$r=app(ClinicOffboardingService::class)->approve(ClinicOffboardingRequest::findOrFail($id),auth()->id(),$d['offboardingReviewNotes']);app(PlatformAuditService::class)->record('CLINIC_OFFBOARDING_APPROVED',$r->clinic_id,[],['request_id'=>$r->id,'status'=>$r->status],$d['offboardingReviewNotes']);$this->reset('offboardingReviewNotes');}
 public function reverseOffboarding(int $id){$d=$this->validate(['offboardingReviewNotes'=>'required|min:3|max:1000']);$r=app(ClinicOffboardingService::class)->reverse(ClinicOffboardingRequest::findOrFail($id),auth()->id(),$d['offboardingReviewNotes']);app(PlatformAuditService::class)->record('CLINIC_OFFBOARDING_REVERSED',$r->clinic_id,[],['request_id'=>$r->id],$d['offboardingReviewNotes']);$this->reset('offboardingReviewNotes');}
 public function retryOffboarding(int $id){$r=ClinicOffboardingRequest::findOrFail($id);abort_unless($r->status==='blocked',422);$r->update(['status'=>'scheduled','blockers'=>null]);app(ClinicOffboardingService::class)->process($r->refresh());}
 #[Computed] public function offboardingRequests(){return ClinicOffboardingRequest::with(['clinic','subscription','requester','approver'])->latest()->limit(100)->get();}
 #[Computed] public function collectionCases(){return SubscriptionCollectionCase::with(['clinic','invoice','notes'])->whereIn('status',['open','promise'])->orderByRaw("FIELD(aging_bucket,'90+','61-90','31-60','1-30','current')")->latest()->get();}
 public function onboardClinic(){ $this->onboardingMessage='';$d=$this->validate($this->onboardingRules());$newClinicId=DB::transaction(function()use($d){$c=Clinic::create(['name'=>$d['newClinicName'],'slug'=>$d['newClinicSlug'],'domain'=>$d['newClinicDomain']?:null,'status'=>'active','deployment_mode'=>$d['newClinicDeployment'],'default_timezone'=>$d['newBranchTimezone'],'default_currency'=>$this->newClinicCurrency,'billing_email'=>$d['newClinicEmail']?:$d['newAdminEmail'],'billing_phone'=>$this->newClinicPhone?:null]);$b=$c->branches()->create(['code'=>strtoupper($d['newBranchCode']),'name'=>$d['newBranchName'],'timezone'=>$d['newBranchTimezone'],'is_default'=>true,'is_active'=>true]);$u=User::create(['name'=>$d['newAdminName'],'email'=>$d['newAdminEmail'],'phone'=>$this->newAdminPhone?:null,'password'=>Hash::make('password')]);$u->forceFill(['must_change_password'=>true])->save();$r=Role::firstOrCreate(['name'=>'Super Admin','guard_name'=>'web']);$u->assignRole($r);$c->users()->attach($u->id,['status'=>'active','clinic_role'=>'Super Admin','invited_by'=>auth()->id(),'joined_at'=>now()]);$b->users()->attach($u->id,['status'=>'active','is_default'=>true,'joined_at'=>now()]);DB::table('branch_user_role')->insert(['branch_id'=>$b->id,'user_id'=>$u->id,'role_id'=>$r->id,'created_at'=>now(),'updated_at'=>now()]);$p=SubscriptionPlan::findOrFail($d['newPlanId']);ClinicSubscription::create(['clinic_id'=>$c->id,'subscription_plan_id'=>$p->id,'status'=>'trial','trial_ends_at'=>now()->addDays($p->trial_days),'current_period_starts_at'=>now(),'current_period_ends_at'=>now()->addDays($p->trial_days)]);app(PlatformAuditService::class)->record('CLINIC_ONBOARDED',$c->id,[],['admin'=>$u->email,'plan'=>$p->name,'temporary_password_required'=>true]);return $c->id;});$this->onboardingMessage='Clinic and Super Admin created successfully.';$this->reset(['newClinicName','newClinicSlug','newClinicDomain','newClinicEmail','newClinicPhone','newAdminName','newAdminEmail','newAdminPhone','newPlanId']);$this->slugEdited=false;if($this->showOnboarding){$this->showOnboarding=false;$this->onboardingStep=1;$this->selectClinic($newClinicId);session()->flash('panel_message','Clinic and Super Admin created. The Super Admin signs in with the temporary password "password" and must change it.');}}
 public function render(){ $all=Clinic::with('currentSubscription.plan')->withCount(['branches'=>fn($q)=>$q->where('is_active',true)])->get();$overLimitIds=$all->filter(fn($c)=>$c->currentSubscription?->branchLimit()!==null&&$c->branches_count>$c->currentSubscription->branchLimit())->pluck('id');$q=Clinic::with('currentSubscription.plan')->withCount(['branches'=>fn($q)=>$q->where('is_active',true)])->when($this->search,fn($q)=>$q->where(fn($x)=>$x->where('name','like','%'.$this->search.'%')->orWhere('domain','like','%'.$this->search.'%')->orWhere('billing_email','like','%'.$this->search.'%')->orWhereHas('users',fn($u)=>$u->where('name','like','%'.$this->search.'%')->orWhere('email','like','%'.$this->search.'%'))))->when($this->deploymentFilter,fn($q)=>$q->where('deployment_mode',$this->deploymentFilter))->when($this->statusFilter,fn($q)=>$q->whereHas('currentSubscription',fn($x)=>$x->where('status',$this->statusFilter)))->when($this->planFilter,fn($q)=>$q->whereHas('currentSubscription',fn($x)=>$x->where('subscription_plan_id',$this->planFilter)))->when($this->productFilter,fn($q)=>$this->whereProduct($q,$this->productFilter))->when($this->limitFilter==='over',fn($q)=>$q->whereIn('id',$overLimitIds))->when($this->limitFilter==='within',fn($q)=>$q->whereNotIn('id',$overLimitIds));return view('livewire.platform.platform-dashboard-component',['clinics'=>$q->orderBy('name')->paginate(12),'allClinics'=>$all,'plans'=>SubscriptionPlan::withCount('subscriptions')->get(),'invoices'=>PlatformInvoice::with('clinic')->latest()->limit(100)->get(),'scheduledChanges'=>SubscriptionChange::with(['clinic','fromPlan','toPlan'])->latest()->limit(100)->get(),'changeRequests'=>SubscriptionChangeRequest::with(['clinic','requestedPlan','requester'])->latest()->limit(100)->get(),'billingNotifications'=>BillingNotificationLog::latest()->limit(100)->get()->load('clinic'),'audits'=>PlatformAuditLog::with(['user','clinic'])->latest()->limit(100)->get(),'invoiceList'=>$this->tab==='billing'&&$this->billingSection==='invoices'?$this->invoiceQuery()->paginate(20,['*'],'invoicesPage'):null,'billingSummary'=>$this->tab==='billing'?$this->billingSummary():[],'billingCounts'=>$this->tab==='billing'?['approvals'=>SubscriptionApprovalRequest::where('status','pending')->count()+SubscriptionChangeRequest::where('status','pending')->count(),'collections'=>SubscriptionCollectionCase::whereIn('status',['open','promise'])->count(),'offboarding'=>ClinicOffboardingRequest::whereIn('status',['pending_approval','blocked'])->count()]:[],'viewInvoice'=>$this->viewInvoiceId?PlatformInvoice::with(['clinic.currentSubscription.plan','payments','subscription'])->find($this->viewInvoiceId):null,'newInvoiceClinic'=>$this->showNewInvoice&&$this->invoiceClinicId?Clinic::with('currentSubscription.plan')->find($this->invoiceClinicId):null,'planPanel'=>$this->showPlanPanel&&$this->editingPlanId?SubscriptionPlan::withCount('subscriptions')->find($this->editingPlanId):null,'planClinics'=>$this->showPlanPanel&&$this->editingPlanId?Clinic::with('currentSubscription')->whereHas('currentSubscription',fn($q)=>$q->where('subscription_plan_id',$this->editingPlanId))->orderBy('name')->get():collect(),'selectedClinic'=>$this->selectedClinicId?Clinic::with(['branches','users','subscriptions.plan','currentSubscription.plan','invoices'=>fn($q)=>$q->latest()])->withCount(['users as active_members_count'=>fn($q)=>$q->where('clinic_user.status','active')])->find($this->selectedClinicId):null,'metrics'=>['total'=>$all->count(),'active'=>$all->where('currentSubscription.status','active')->count(),'trials'=>$all->where('currentSubscription.status','trial')->count(),'overdue'=>$all->whereIn('currentSubscription.status',['overdue','restricted'])->count(),'overlimit'=>$overLimitIds->count(),'mrr'=>ClinicSubscription::where('status','active')->get()->sum(fn($s)=>$s->billing_interval==='yearly'?(float)data_get($s->pricing_snapshot,'annual_price',0)/12:(float)data_get($s->pricing_snapshot,'base_price',0))]])->layout('layouts.platform');}
}
