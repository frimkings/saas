<?php
namespace App\Services;
use App\Models\LegacyImportBatch;
use Illuminate\Support\Facades\Storage;
class LegacyImportEvidenceService {
 public function archive(LegacyImportBatch $batch):array{
  $batch->refresh();$manifest=['version'=>1,'batch_uuid'=>$batch->uuid,'source'=>['filename'=>$batch->original_filename,'stored_path'=>$batch->stored_path,'sha256'=>$batch->checksum,'schema_summary'=>$batch->analysis['schema']??$batch->analysis['tables']??[],'record_counts'=>$batch->analysis['counts']??[]],'import_options'=>$batch->result['import_options']??['clinic_name'=>$batch->clinic_name,'clinic_slug'=>$batch->clinic_slug,'admin_email'=>$batch->admin_email,'plan_id'=>$batch->plan_id],'conflict_decisions'=>$batch->conflicts,'operator'=>['id'=>$batch->created_by,'email'=>$batch->creator?->email],'timestamps'=>['analyzed_at'=>$batch->analyzed_at?->toIso8601String(),'started_at'=>$batch->started_at?->toIso8601String(),'committed_at'=>$batch->committed_at?->toIso8601String(),'archived_at'=>now()->toIso8601String()],'reconciliation'=>$batch->result['validation_report']??null,'sampling'=>$batch->result['sampling_report']??null,'readiness'=>$batch->readiness_report];$json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);$path='legacy-import-evidence/'.$batch->uuid.'/manifest.json';Storage::disk('local')->put($path,$json);$checksum=hash('sha256',$json);$batch->update(['evidence_manifest'=>$manifest,'evidence_path'=>$path,'evidence_checksum'=>$checksum,'evidence_archived_at'=>now()]);return $manifest;
 }
}
