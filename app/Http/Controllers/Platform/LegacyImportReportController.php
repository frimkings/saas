<?php
namespace App\Http\Controllers\Platform;
use App\Http\Controllers\Controller;
use App\Models\LegacyImportBatch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
class LegacyImportReportController extends Controller {
 public function csv(LegacyImportBatch $batch){abort_unless($batch->committed_at,404);$rows=$this->rows($batch);return response()->streamDownload(function()use($rows){$out=fopen('php://output','w');fputcsv($out,['Section','Check/Table','Source','Destination/Imported','Difference','Failures','Status']);foreach($rows as $row)fputcsv($out,$row);fclose($out);},'legacy-import-'.$batch->uuid.'-validation.csv',['Content-Type'=>'text/csv']);}
 public function pdf(LegacyImportBatch $batch){abort_unless($batch->committed_at,404);$pdf=Pdf::loadView('platform.legacy-import-validation-pdf',['batch'=>$batch,'rows'=>$this->rows($batch)])->setPaper('a4','landscape');return $pdf->download('legacy-import-'.$batch->uuid.'-validation.pdf');}
 public function evidence(LegacyImportBatch $batch):Response{abort_unless($batch->evidence_path&&Storage::disk('local')->exists($batch->evidence_path),404);$contents=Storage::disk('local')->get($batch->evidence_path);abort_unless(hash_equals((string)$batch->evidence_checksum,hash('sha256',$contents)),409,'Evidence integrity verification failed.');return response($contents,200,['Content-Type'=>'application/json','Content-Disposition'=>'attachment; filename="legacy-import-'.$batch->uuid.'-evidence.json"']);}
 private function rows(LegacyImportBatch $batch):array{$rows=[];foreach(($batch->result['validation_report']['tables']??[]) as $table=>$r)$rows[]=['Reconciliation',$table,$r['source'],$r['imported'],$r['difference'],$r['relationship_failures'],$r['status']];foreach(($batch->result['sampling_report']['checks']??[]) as $r)$rows[]=['Sampling',$r['label'],$r['source'],$r['destination'],$r['difference']??'','',''.$r['status']];foreach(($batch->readiness_report['checks']??[]) as $r)$rows[]=['Readiness',$r['label'],'',$r['detail']??'','','',$r['passed']?'PASS':'FAIL'];return $rows;}
}
