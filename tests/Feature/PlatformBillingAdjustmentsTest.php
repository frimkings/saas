<?php
namespace Tests\Feature;
use App\Models\{Clinic,PlatformInvoice,PlatformPayment};
use App\Services\BillingAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class PlatformBillingAdjustmentsTest extends TestCase
{
 use RefreshDatabase;
 private function invoice(array $extra=[]):PlatformInvoice{$clinic=Clinic::create(['name'=>'Accounts Clinic','slug'=>'accounts-clinic-'.uniqid()]);return PlatformInvoice::create(array_merge(['number'=>'INV-ADJ-'.uniqid(),'clinic_id'=>$clinic->id,'period_start'=>now(),'period_end'=>now()->addMonth(),'due_date'=>now()->addWeek(),'subtotal'=>100,'tax'=>0,'total'=>100,'amount_paid'=>0,'currency'=>'GHS','status'=>'unpaid'],$extra));}
 public function test_credit_note_reduces_balance_without_rewriting_invoice_total():void{$invoice=$this->invoice();$note=app(BillingAdjustmentService::class)->credit($invoice,40,'Service adjustment',null);$this->assertSame('40.00',$note->amount);$this->assertSame('100.00',$invoice->refresh()->total);$this->assertSame(60.0,$invoice->balance());$this->expectException(ValidationException::class);app(BillingAdjustmentService::class)->credit($invoice,61,'Excess credit attempt',null);}
 public function test_refund_is_capped_by_original_payment_and_reopens_balance():void{$invoice=$this->invoice(['amount_paid'=>100,'status'=>'paid']);$payment=PlatformPayment::create(['platform_invoice_id'=>$invoice->id,'amount'=>100,'method'=>'bank_transfer','reference'=>'PAY-1','status'=>'confirmed','paid_at'=>now()]);$refund=app(BillingAdjustmentService::class)->refund($payment,30,'bank_transfer','Customer refund','RF-EXT-1',null);$this->assertSame('30.00',$refund->amount);$this->assertSame(30.0,$invoice->refresh()->balance());$this->assertSame('partial',$invoice->status);$this->expectException(ValidationException::class);app(BillingAdjustmentService::class)->refund($payment,71,'cash','Too much refund',null,null);}
 public function test_only_unpaid_unadjusted_invoice_can_be_voided():void{$invoice=$this->invoice();app(BillingAdjustmentService::class)->void($invoice,'Duplicate invoice',null);$this->assertSame('void',$invoice->refresh()->status);$paid=$this->invoice(['amount_paid'=>10,'status'=>'partial']);$this->expectException(ValidationException::class);app(BillingAdjustmentService::class)->void($paid,'Invalid void',null);}
}
