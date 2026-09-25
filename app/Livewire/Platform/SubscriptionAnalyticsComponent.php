<?php
namespace App\Livewire\Platform;
use App\Models\{Clinic,SubscriptionPlan};
use App\Services\SubscriptionAnalyticsService;
use Carbon\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
class SubscriptionAnalyticsComponent extends Component
{
 #[Url] public string $from='';#[Url] public string $to='';#[Url] public string $clinicId='';#[Url] public string $planId='';#[Url] public string $status='';#[Url] public string $product='';
 public function mount():void{if(!$this->from)$this->from=now()->startOfYear()->toDateString();if(!$this->to)$this->to=now()->toDateString();}
 public function preset(string $range):void{[$f,$t]=match($range){'month'=>[now()->startOfMonth(),now()],'30d'=>[now()->subDays(29),now()],'quarter'=>[now()->startOfQuarter(),now()],'12m'=>[now()->subMonths(11)->startOfMonth(),now()],'lastyear'=>[now()->subYear()->startOfYear(),now()->subYear()->endOfYear()],default=>[now()->startOfYear(),now()]};$this->from=$f->toDateString();$this->to=$t->toDateString();}
 public function resetFilters():void{$this->from=now()->startOfYear()->toDateString();$this->to=now()->toDateString();$this->clinicId=$this->planId=$this->status=$this->product='';}
 public function render(){ $from=Carbon::parse($this->from)->startOfDay();$to=Carbon::parse($this->to)->endOfDay();if($from->gt($to))[$from,$to]=[$to->copy()->startOfDay(),$from->copy()->endOfDay()];$report=app(SubscriptionAnalyticsService::class)->report($from,$to,['clinic_id'=>$this->clinicId?:null,'plan_id'=>$this->planId?:null,'status'=>$this->status?:null,'product'=>array_key_exists($this->product,\App\Support\PlanProduct::PRODUCTS)?$this->product:null]);unset($report['from'],$report['to']);return view('livewire.platform.subscription-analytics-component',$report+['analyticsFrom'=>$from,'analyticsTo'=>$to,'clinics'=>Clinic::orderBy('name')->get(),'plans'=>SubscriptionPlan::orderBy('name')->get()])->layout('layouts.platform');}
}
