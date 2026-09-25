<?php
namespace App\Http\Controllers\Platform;
use App\Http\Controllers\Controller;
use App\Models\{PlatformCreditNote,PlatformInvoice,PlatformPayment,PlatformPaymentRefund};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
class BillingReportController extends Controller
{
 private function data(Request $request):array{$from=$request->date('from')??now()->startOfMonth();$to=$request->date('to')??now()->endOfMonth();$invoices=PlatformInvoice::with('clinic')->whereBetween('created_at',[$from->copy()->startOfDay(),$to->copy()->endOfDay()])->get();return compact('from','to','invoices')+['invoiced'=>$invoices->where('status','!=','void')->sum('total'),'collected'=>PlatformPayment::whereBetween('paid_at',[$from->copy()->startOfDay(),$to->copy()->endOfDay()])->sum('amount'),'refunded'=>PlatformPaymentRefund::whereBetween('refunded_at',[$from->copy()->startOfDay(),$to->copy()->endOfDay()])->sum('amount'),'credited'=>PlatformCreditNote::whereBetween('issued_at',[$from->copy()->startOfDay(),$to->copy()->endOfDay()])->sum('amount'),'outstanding'=>$invoices->where('status','!=','void')->sum(fn($i)=>$i->balance())];}
 public function csv(Request $request){$d=$this->data($request);return response()->streamDownload(function()use($d){$h=fopen('php://output','w');fputcsv($h,['Invoice','Clinic','Issued','Due','Currency','Total','Paid','Credits','Refunds','Balance','Status']);foreach($d['invoices'] as $i)fputcsv($h,[$i->number,$i->clinic->name,$i->created_at->toDateString(),$i->due_date->toDateString(),$i->currency,$i->total,$i->amount_paid,$i->credited_amount,$i->refunded_amount,$i->balance(),$i->status]);fclose($h);},'billing-report-'.$d['from']->toDateString().'-'.$d['to']->toDateString().'.csv',['Content-Type'=>'text/csv']);}
 public function pdf(Request $request){$d=$this->data($request);return Pdf::loadView('pdf.platform-billing-report',$d)->setPaper('a4','landscape')->stream('billing-report.pdf');}
}
