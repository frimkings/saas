<?php
namespace App\Http\Controllers\Platform;
use App\Http\Controllers\Controller;
use App\Services\SubscriptionAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
class SubscriptionAnalyticsExportController extends Controller
{public function csv(Request $request){$from=Carbon::parse($request->input('from',now()->startOfYear()->toDateString()))->startOfDay();$to=Carbon::parse($request->input('to',now()->toDateString()))->endOfDay();$r=app(SubscriptionAnalyticsService::class)->report($from,$to,$request->only(['clinic_id','plan_id','status']));return response()->streamDownload(function()use($r){$h=fopen('php://output','w');fputcsv($h,['Subscription Analytics',$r['from']->toDateString().' to '.$r['to']->toDateString()]);foreach($r['metrics'] as $key=>$value)fputcsv($h,[str_replace('_',' ',ucfirst($key)),$value]);fputcsv($h,[]);fputcsv($h,['Plan','Clinics','MRR']);foreach($r['by_plan'] as $name=>$row)fputcsv($h,[$name,$row['clinics'],$row['mrr']]);fclose($h);},'subscription-analytics-'.$from->toDateString().'-'.$to->toDateString().'.csv',['Content-Type'=>'text/csv']);}}
