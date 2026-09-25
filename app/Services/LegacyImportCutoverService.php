<?php

namespace App\Services;

use App\Models\LegacyImportBatch;
use Illuminate\Support\Facades\DB;

class LegacyImportCutoverService
{
    public function checklist(LegacyImportBatch $batch): array
    {
        $clinicId=$batch->clinic_id;
        $validation=$batch->result['validation_report']??[];
        $sampling=$batch->result['sampling_report']??[];
        $items=[
            'import_completed'=>['label'=>'Import transaction completed','passed'=>(bool)$batch->committed_at&&!$batch->rolled_back_at],
            'record_reconciliation'=>['label'=>'Record counts and relationships passed','passed'=>(bool)($validation['passed']??false)],
            'validation_sampling'=>['label'=>'Representative records and totals passed','passed'=>(bool)($sampling['passed']??false)],
            'operational_readiness'=>['label'=>'Operational readiness checklist passed','passed'=>(bool)($batch->readiness_report['passed']??false)],
            'clinic_exists'=>['label'=>'Destination clinic exists','passed'=>$clinicId&&DB::table('clinics')->where('id',$clinicId)->exists()],
            'default_branch'=>['label'=>'Active default branch exists','passed'=>$clinicId&&DB::table('branches')->where('clinic_id',$clinicId)->where('is_default',1)->where('is_active',1)->exists()],
            'super_admin'=>['label'=>'Clinic has an active Super Admin membership','passed'=>$clinicId&&DB::table('clinic_user')->where('clinic_id',$clinicId)->where('status','active')->where('clinic_role','Super Admin')->exists()],
            'subscription'=>['label'=>'Clinic has an active or trial subscription','passed'=>$clinicId&&DB::table('clinic_subscriptions')->where('clinic_id',$clinicId)->whereIn('status',['active','trial'])->exists()],
        ];
        return ['passed'=>collect($items)->every(fn($item)=>$item['passed']),'generated_at'=>now()->toIso8601String(),'items'=>$items];
    }

    public function approve(LegacyImportBatch $batch,int $userId,?string $notes=null): array
    {
        abort_if($batch->rolled_back_at,422,'A rolled-back import cannot be approved.');
        abort_if($batch->cutover_approved_at,422,'This import has already been approved for cutover.');
        $checklist=$this->checklist($batch);
        abort_unless($checklist['passed'],422,'All cutover readiness checks must pass before approval.');
        $batch->update(['cutover_status'=>'approved','cutover_checklist'=>$checklist,'cutover_approved_by'=>$userId,'cutover_approved_at'=>now(),'cutover_notes'=>trim((string)$notes)?:null]);
        return $checklist;
    }
}
