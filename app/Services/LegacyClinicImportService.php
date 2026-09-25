<?php

namespace App\Services;

use App\Models\LegacyImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

class LegacyClinicImportService
{
    public function commit(LegacyImportBatch $batch, array $options, ?callable $progress = null): array
    {
        abort_if($batch->committed_at, 422, 'This batch has already been committed.');
        abort_if(! hash_equals($batch->checksum, hash_file('sha256', Storage::disk('local')->path($batch->stored_path))), 422, 'The uploaded SQL checksum has changed.');
        $source='legacy_'.Str::lower(Str::random(16));
        $progress && $progress(8, 'staging_restore', 0, 0);
        $this->createStagingDatabase($source, Storage::disk('local')->path($batch->stored_path));
        $progress && $progress(15, 'staging_ready', 0, 0);
        try { return $this->copy($batch,$source,$options,$progress); }
        finally { DB::statement("DROP DATABASE IF EXISTS `$source`"); DB::purge('legacy_import'); }
    }

    private function createStagingDatabase(string $database,string $file): void
    {
        DB::statement("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $c=config('database.connections.mysql');
        $process=new Process(['mysql','--host='.$c['host'],'--port='.$c['port'],'--user='.$c['username'],$database]);
        $process->setEnv(array_merge($_ENV,['MYSQL_PWD'=>(string)($c['password']??'')]))->setInput(fopen($file,'rb'))->setTimeout(300)->mustRun();
    }

    private function copy(LegacyImportBatch $batch,string $source,array $o,?callable $progress=null): array
    {
        config(['database.connections.legacy_import'=>array_merge(config('database.connections.mysql'),['database'=>$source])]);DB::purge('legacy_import');$legacy=DB::connection('legacy_import');
        $result=DB::transaction(function()use($batch,$legacy,$o,$progress){
            $now=now();$clinicId=DB::table('clinics')->insertGetId(['uuid'=>(string)Str::uuid(),'name'=>$o['clinic_name'],'slug'=>$o['clinic_slug'],'status'=>'active','deployment_mode'=>$o['deployment_mode'],'default_timezone'=>$o['timezone'],'default_currency'=>$o['currency'],'billing_email'=>$o['admin_email'],'created_at'=>$now,'updated_at'=>$now]);
            $branchId=DB::table('branches')->insertGetId(['uuid'=>(string)Str::uuid(),'clinic_id'=>$clinicId,'code'=>strtoupper($o['branch_code']),'name'=>$o['branch_name'],'timezone'=>$o['timezone'],'is_default'=>1,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
            $plan=DB::table('subscription_plans')->find($o['plan_id']);abort_unless($plan,422,'Selected subscription plan no longer exists.');DB::table('clinic_subscriptions')->insert(['clinic_id'=>$clinicId,'subscription_plan_id'=>$plan->id,'status'=>'active','billing_interval'=>'monthly','renewal_mode'=>'manual','current_period_starts_at'=>$now,'current_period_ends_at'=>$now->copy()->addMonth(),'created_at'=>$now,'updated_at'=>$now]);
            $maps=[];$createdUsers=[];$counts=[];$relationshipFailures=[];$relationshipFailureDetails=[];$deferredLinks=[];
            $supported=$this->supportedTables();
            $sourceCounts=[];
            foreach($supported as $table){
                $sourceCounts[$table]=$legacy->getSchemaBuilder()->hasTable($table)
                    ? $legacy->table($table)->count()
                    : 0;
            }
            $totalRows=array_sum($sourceCounts);$processedRows=0;
            $progress && $progress(20,'users',0,$totalRows);
            $fallbackOwner=null;
            $copy=function(string $table,array $foreign=[],array $deferred=[])use(&$fallbackOwner,&$maps,&$counts,&$relationshipFailures,&$relationshipFailureDetails,&$deferredLinks,&$processedRows,$sourceCounts,$totalRows,$progress,$clinicId,$branchId,$legacy){if(!$legacy->getSchemaBuilder()->hasTable($table)||!Schema::hasTable($table))return;$progress && $progress(20+(int)floor(($processedRows/max(1,$totalRows))*70),$table,$processedRows,$totalRows);$columns=Schema::getColumnListing($table);$nullable=collect(Schema::getColumns($table))->where('nullable',true)->pluck('name')->all();foreach($legacy->table($table)->orderBy('id')->get() as $old){$row=array_intersect_key((array)$old,array_flip($columns));unset($row['id']);if(array_key_exists('uuid',$row))$row['uuid']=(string)Str::uuid();if(!empty($row['idempotency_key']))$row['idempotency_key']=(string)Str::uuid();if($table==='sales'&&!empty($row['transaction_id']))$row['transaction_id']='IMP'.$clinicId.'-'.$row['transaction_id'];if(in_array('clinic_id',$columns,true))$row['clinic_id']=$clinicId;if(in_array('branch_id',$columns,true))$row['branch_id']=$branchId;if($table==='patients'&&in_array('home_branch_id',$columns,true))$row['home_branch_id']=$branchId;if($table==='stock_transfers'&&in_array('destination_branch_id',$columns,true))$row['destination_branch_id']=$branchId;foreach($deferred as $column=>$parent)if(array_key_exists($column,$row)&&$row[$column]!==null){if((int)$row[$column]>0)$deferredLinks[$table][$old->id][$column]=[$parent,$row[$column]];$row[$column]=null;}foreach($foreign as $column=>$parent)if(array_key_exists($column,$row)&&$row[$column]!==null){$legacyId=$row[$column];if((int)$legacyId<=0){$row[$column]=null;continue;}$row[$column]=$maps[$parent][$legacyId]??null;if($row[$column]===null){$reassign=$parent==='users'&&$fallbackOwner&&!in_array($column,$nullable,true);if($reassign)$row[$column]=$fallbackOwner;$relationshipFailures[$table]=($relationshipFailures[$table]??0)+1;if(count($relationshipFailureDetails[$table]??[])<100)$relationshipFailureDetails[$table][]=['legacy_row_id'=>(int)$old->id,'column'=>$column,'missing_parent_table'=>$parent,'missing_parent_legacy_id'=>(int)$legacyId,'action'=>$reassign?'reassigned_to_owner':'set_null'];}}$maps[$table][$old->id]=DB::table($table)->insertGetId($row);$counts[$table]=($counts[$table]??0)+1;}$processedRows+=($sourceCounts[$table]??0);$progress && $progress(20+(int)floor(($processedRows/max(1,$totalRows))*70),$table,$processedRows,$totalRows);};
            $resolveDeferred=function(string $table)use(&$maps,&$relationshipFailures,&$relationshipFailureDetails,&$deferredLinks){foreach($deferredLinks[$table]??[] as $oldId=>$links){foreach($links as $column=>[$parent,$legacyId]){$newParentId=$maps[$parent][$legacyId]??null;if($newParentId===null){$relationshipFailures[$table]=($relationshipFailures[$table]??0)+1;if(count($relationshipFailureDetails[$table]??[])<100)$relationshipFailureDetails[$table][]=['legacy_row_id'=>(int)$oldId,'column'=>$column,'missing_parent_table'=>$parent,'missing_parent_legacy_id'=>(int)$legacyId,'action'=>'set_null'];}DB::table($table)->where('id',$maps[$table][$oldId])->update([$column=>$newParentId]);}}};
            $roleIds=$legacy->table('roles')->pluck('name','id');$assignments=$legacy->table('model_has_roles')->get()->groupBy('model_id');$superLegacyId=$assignments->first(fn($rows)=>$rows->contains(fn($r)=>($roleIds[$r->role_id]??null)==='Super Admin'))?->first()?->model_id;
            foreach($legacy->table('users')->orderBy('id')->get() as $old){$sourceEmail=strtolower(trim((string)$old->email));$email=(int)$old->id===(int)$superLegacyId?strtolower($o['admin_email']):$sourceEmail;$resolution=$o['conflicts'][(string)$old->id]??$o['conflicts'][$sourceEmail]??['action'=>'create','email'=>''];$decision=is_array($resolution)?($resolution['action']??'create'):$resolution;if($decision==='skip'){$maps['users'][$old->id]=null;continue;}if($decision==='replace')$email=strtolower(trim((string)($resolution['email']??'')));$id=$decision==='merge'?DB::table('users')->whereRaw('LOWER(email)=?',[$sourceEmail])->value('id'):null;if($decision==='merge'&&!$id)throw new \RuntimeException("The selected merge identity {$sourceEmail} no longer exists.");if(!$id){if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException("A valid destination email is required for legacy user {$old->id}.");if(DB::table('users')->whereRaw('LOWER(email)=?',[$email])->exists())throw new \RuntimeException("The destination email {$email} is already in use.");$row=array_intersect_key((array)$old,array_flip(Schema::getColumnListing('users')));unset($row['id'],$row['staff_id']);$row['email']=$email;if((int)$old->id===(int)$superLegacyId){$row['password']=Hash::make('password');$row['must_change_password']=1;}$id=DB::table('users')->insertGetId($row);$createdUsers[]=$id;}$maps['users'][$old->id]=$id;}$counts['users']=collect($maps['users'])->filter()->count();
            // Required owner columns (categories/products.user_id) cannot be NULL, so rows owned by a skipped user go to the clinic owner.
            $fallbackOwner=$maps['users'][$superLegacyId]??collect($maps['users'])->filter()->first();
            foreach(['categories','diagnoses','expense_categories','insurers','lens_options','referral_snippets','sms_templates','suppliers'] as $t)$copy($t,['user_id'=>'users','created_by'=>'users']);$copy('patients',['user_id'=>'users','insurer_id'=>'insurers']);$copy('products',['user_id'=>'users','category_id'=>'categories']);$copy('appointments',['patient_id'=>'patients','doctor_id'=>'users','user_id'=>'users']);$copy('expenses',['expense_category_id'=>'expense_categories','recorded_by'=>'users']);

            // Break the clearance -> sale -> consultation -> clearance cycle by
            // deferring clearance.sale_id until all three tables have ID maps.
            $copy('cashier_patient_clearances',['user_id'=>'users','patient_id'=>'patients','service_id'=>'products'],['sale_id'=>'sales']);
            $copy('consultations',['user_id'=>'users','patient_id'=>'patients','clearance_id'=>'cashier_patient_clearances']);
            $copy('refractions',['user_id'=>'users','consultation_id'=>'consultations','dispensing_authorized_by'=>'users']);
            $copy('carts',['patient_id'=>'patients','product_id'=>'products','consultation_id'=>'consultations','dispensed_by'=>'users']);
            $copy('sales',['user_id'=>'users','patient_id'=>'patients','consultation_id'=>'consultations','discount_approved_by'=>'users','refunded_by'=>'users','finalized_by'=>'users']);

            $resolveDeferred('cashier_patient_clearances');

            $copy('sale_items',['sale_id'=>'sales','product_id'=>'products','cart_id'=>'carts']);
            $copy('payment_transactions',['sale_id'=>'sales','collected_by'=>'users']);
            $copy('stock_movements',['product_id'=>'products','user_id'=>'users']);
            $copy('consultation_notes',['consultation_id'=>'consultations','patient_id'=>'patients','user_id'=>'users']);
            $copy('lens_orders',['refraction_id'=>'refractions','user_id'=>'users','frame_product_id'=>'products','lens_product_id'=>'products','renewal_approved_by'=>'users']);
            if($legacy->getSchemaBuilder()->hasTable('consultation_diagnosis')&&Schema::hasTable('consultation_diagnosis')){foreach($legacy->table('consultation_diagnosis')->get() as $old){$consultationId=$maps['consultations'][$old->consultation_id]??null;$diagnosisId=$maps['diagnoses'][$old->diagnosis_id]??null;if(!$consultationId||!$diagnosisId){$relationshipFailures['consultation_diagnosis']=($relationshipFailures['consultation_diagnosis']??0)+1;if(count($relationshipFailureDetails['consultation_diagnosis']??[])<100)$relationshipFailureDetails['consultation_diagnosis'][]=['legacy_row_id'=>($old->id??null),'column'=>!$consultationId?'consultation_id':'diagnosis_id','missing_parent_table'=>!$consultationId?'consultations':'diagnoses','missing_parent_legacy_id'=>(int)(!$consultationId?$old->consultation_id:$old->diagnosis_id),'action'=>'skip_row'];continue;}DB::table('consultation_diagnosis')->insertOrIgnore(['consultation_id'=>$consultationId,'diagnosis_id'=>$diagnosisId]);$counts['consultation_diagnosis']=($counts['consultation_diagnosis']??0)+1;}}
            $copy('insurance_claims',['patient_id'=>'patients','insurer_id'=>'insurers','sale_id'=>'sales','created_by'=>'users','updated_by'=>'users']);
            $copy('quotations',['patient_id'=>'patients','created_by'=>'users']);
            $copy('quotation_items',['quotation_id'=>'quotations','product_id'=>'products']);
            $copy('purchase_orders',['supplier_id'=>'suppliers','created_by'=>'users','received_by'=>'users']);
            $copy('purchase_order_items',['purchase_order_id'=>'purchase_orders','product_id'=>'products']);
            $copy('inventory_lots',['product_id'=>'products']);
            $copy('branch_inventory_items',['product_id'=>'products']);
            $copy('stock_transfers',['created_by'=>'users','dispatched_by'=>'users','received_by'=>'users']);
            $copy('stock_transfer_items',['stock_transfer_id'=>'stock_transfers','product_id'=>'products','inventory_lot_id'=>'inventory_lots']);
            $copy('refund_logs',['sale_id'=>'sales','initiated_by'=>'users','approved_by'=>'users','rejected_by'=>'users','processed_by'=>'users']);
            $copy('sale_adjustments',['sale_id'=>'sales','approved_by'=>'users','created_by'=>'users']);
            $copy('discount_approval_requests',['cashier_id'=>'users','patient_id'=>'patients','approved_by'=>'users','rejected_by'=>'users']);
            $copy('clearance_revoke_logs',['clearance_id'=>'cashier_patient_clearances','requested_by'=>'users','approved_by'=>'users','rejected_by'=>'users']);
            $copy('referrals',['patient_id'=>'patients','referred_by'=>'users','issued_by'=>'users','updated_by'=>'users','printed_by'=>'users']);
            $copy('patient_documents',['patient_id'=>'patients','consultation_id'=>'consultations','uploaded_by'=>'users']);
            $copy('app_notifications',['user_id'=>'users']);
            $copy('sms_logs',['patient_id'=>'patients']);
            $copy('report_deliveries');
            $copy('staff_messages',['sender_id'=>'users','recipient_id'=>'users'],['parent_id'=>'staff_messages']);
            $resolveDeferred('staff_messages');
            $copy('login_logs',['user_id'=>'users']);$copy('settings');
            // Staff IDs are per clinic: they go on the membership, never on the platform-wide account.
            $legacyStaffIds=$legacy->getSchemaBuilder()->hasColumn('users','staff_id')?$legacy->table('users')->pluck('staff_id','id')->all():[];
            foreach($maps['users']??[] as $oldId=>$userId){if(!$userId)continue;$names=collect($assignments[$oldId]??[])->map(fn($a)=>$roleIds[$a->role_id]??null)->filter();if((int)$oldId===(int)$superLegacyId)$names=collect(['Super Admin']);$hasDefaultClinic=DB::table('clinic_user')->where('user_id',$userId)->where('clinic_id','<>',$clinicId)->where('status','active')->where('is_default',1)->exists();$staffNo=trim((string)($legacyStaffIds[$oldId]??''));if($staffNo===''||DB::table('clinic_user')->where('clinic_id',$clinicId)->where('staff_identifier',$staffNo)->exists())$staffNo=null;DB::table('clinic_user')->insertOrIgnore(['clinic_id'=>$clinicId,'user_id'=>$userId,'status'=>'active','staff_identifier'=>$staffNo,'clinic_role'=>$names->first()??'Staff','is_default'=>$hasDefaultClinic?0:1,'joined_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);DB::table('branch_user')->insertOrIgnore(['branch_id'=>$branchId,'user_id'=>$userId,'status'=>'active','is_default'=>1,'joined_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);foreach($names as $name){$role=Role::firstOrCreate(['name'=>$name,'guard_name'=>'web']);DB::table('branch_user_role')->insertOrIgnore(['branch_id'=>$branchId,'user_id'=>$userId,'role_id'=>$role->id,'created_at'=>$now,'updated_at'=>$now]);}}
            $samplingReport=$this->buildValidationSampling($legacy,$maps,$clinicId,$branchId);
            $completed=['clinic_id'=>$clinicId,'branch_id'=>$branchId,'import_options'=>$o,'created_user_ids'=>$createdUsers,'source_counts'=>$sourceCounts,'counts'=>$counts,'relationship_failures'=>$relationshipFailures,'relationship_failure_details'=>$relationshipFailureDetails,'sampling_report'=>$samplingReport];
            $completed['validation_report']=$this->buildValidationReport($batch,$completed);
            $completed['validation_report']['sampling_passed']=$samplingReport['passed']??false;
            $completed['validation_report']['passed']=$completed['validation_report']['passed']&&$completed['validation_report']['sampling_passed'];
            $batch->update(['clinic_id'=>$clinicId,'plan_id'=>$o['plan_id'],'clinic_name'=>$o['clinic_name'],'clinic_slug'=>$o['clinic_slug'],'admin_email'=>$o['admin_email'],'status'=>$completed['validation_report']['passed']?'committed':'review_required','result'=>$completed,'committed_at'=>now(),'error'=>null,'resume_checkpoint'=>['phase'=>'committed','table'=>'complete','processed'=>$totalRows,'total'=>$totalRows],'checkpoint_at'=>now()]);
            return $completed;
        });
        return $result;
    }

    public function buildValidationReport(LegacyImportBatch $batch,array $result):array
    {
        $supported=$this->supportedTables();$source=$result['source_counts']??$batch->analysis['counts']??[];$imported=$result['counts']??[];$relationships=$result['relationship_failures']??[];$details=$result['relationship_failure_details']??[];$rows=[];
        foreach($supported as $table){$sourceCount=(int)($source[$table]??0);$importedCount=(int)($imported[$table]??0);$difference=$importedCount-$sourceCount;$failures=(int)($relationships[$table]??0);$rows[$table]=['source'=>$sourceCount,'imported'=>$importedCount,'difference'=>$difference,'relationship_failures'=>$failures,'relationship_failure_details'=>$details[$table]??[],'details_truncated'=>$failures>count($details[$table]??[]),'status'=>$difference===0&&$failures===0?'PASS':'FAIL'];}
        return ['passed'=>collect($rows)->every(fn($row)=>$row['status']==='PASS'),'generated_at'=>now()->toIso8601String(),'tables'=>$rows,'totals'=>['source'=>array_sum(array_column($rows,'source')),'imported'=>array_sum(array_column($rows,'imported')),'difference'=>array_sum(array_column($rows,'difference')),'relationship_failures'=>array_sum(array_column($rows,'relationship_failures'))]];
    }

    /**
     * Compare representative legacy records and important operational totals with
     * their newly mapped tenant records while the staging database is available.
     */
    public function buildValidationSampling($legacy,array $maps,int $clinicId,int $branchId): array
    {
        $checks=[];
        $add=function(string $category,string $label,mixed $source,mixed $destination,string $status,array $context=[])use(&$checks):void{
            $checks[]=array_merge(['category'=>$category,'label'=>$label,'source'=>$source,'destination'=>$destination,'status'=>$status],$context);
        };
        $normalise=fn(mixed $value):string=>$value===null?'NULL':(is_bool($value)?($value?'1':'0'):trim((string)$value));
        $sampleIds=function(string $table)use($legacy):array{
            if(!$legacy->getSchemaBuilder()->hasTable($table))return [];
            $ids=$legacy->table($table)->orderBy('id')->pluck('id')->values();
            if($ids->isEmpty())return [];
            return collect([$ids->first(),$ids->get((int)floor(($ids->count()-1)/2)),$ids->last()])->filter(fn($id)=>$id!==null)->unique()->values()->all();
        };

        $definitions=[
            'patients'=>['fields'=>['pxnumber','name','dob','gender','contact'],'foreign'=>['user_id'=>'users','insurer_id'=>'insurers']],
            'consultations'=>['fields'=>['chiefComplaint','IOPOD','IOPOS'],'foreign'=>['patient_id'=>'patients','user_id'=>'users','clearance_id'=>'cashier_patient_clearances']],
            'sales'=>['fields'=>['total_amount','amount_paid','payment_status','bill_status'],'foreign'=>['patient_id'=>'patients','user_id'=>'users','consultation_id'=>'consultations']],
        ];
        foreach($definitions as $table=>$definition){
            foreach($sampleIds($table) as $legacyId){
                $destinationId=$maps[$table][$legacyId]??null;
                $sourceRow=$legacy->table($table)->find($legacyId);
                $destinationRow=$destinationId?DB::table($table)->where('clinic_id',$clinicId)->find($destinationId):null;
                if(!$sourceRow||!$destinationRow){
                    $add('record_sample',"{$table} #{$legacyId}",'Present',$destinationRow?'Present':'Missing','FAIL',['table'=>$table,'legacy_id'=>(int)$legacyId,'destination_id'=>$destinationId]);
                    continue;
                }
                $mismatches=[];
                foreach($definition['fields'] as $field){
                    if(property_exists($sourceRow,$field)&&$normalise($sourceRow->{$field})!==$normalise($destinationRow->{$field}??null))$mismatches[]=$field;
                }
                foreach($definition['foreign'] as $field=>$parent){
                    if(!property_exists($sourceRow,$field)||$sourceRow->{$field}===null)continue;
                    $expected=(int)$sourceRow->{$field}>0?($maps[$parent][$sourceRow->{$field}]??null):null;
                    if((string)$expected!==(string)($destinationRow->{$field}??null))$mismatches[]=$field;
                }
                $add('record_sample',"{$table} #{$legacyId}",'Mapped record',$mismatches?'Mismatch: '.implode(', ',$mismatches):'Matched',$mismatches?'FAIL':'PASS',['table'=>$table,'legacy_id'=>(int)$legacyId,'destination_id'=>(int)$destinationId]);
            }
        }

        $aggregates=[
            ['sales','total_amount','Sales total'],
            ['sales','amount_paid','Sales amount paid'],
            ['payment_transactions','amount','Payment total'],
            ['products','quantity','Product stock balance'],
            ['stock_movements','quantity','Stock movement quantity'],
        ];
        foreach($aggregates as [$table,$column,$label]){
            if(!$legacy->getSchemaBuilder()->hasTable($table)||!Schema::hasTable($table)||!$legacy->getSchemaBuilder()->hasColumn($table,$column)||!Schema::hasColumn($table,$column)){
                $add('aggregate',$label,'Unavailable','Unavailable','SKIP',['table'=>$table]);continue;
            }
            $source=(float)$legacy->table($table)->sum($column);
            $destinationIds=array_values(array_filter($maps[$table]??[]));
            $destination=$destinationIds?(float)DB::table($table)->whereIn('id',$destinationIds)->sum($column):0.0;
            $status=abs($source-$destination)<0.01?'PASS':'FAIL';
            $add('aggregate',$label,number_format($source,2,'.',''),number_format($destination,2,'.',''),$status,['table'=>$table,'difference'=>round($destination-$source,2)]);
        }

        $relationshipChecks=collect($definitions)->sum(fn($definition)=>count($definition['foreign']));
        $failed=collect($checks)->where('status','FAIL')->count();
        return ['passed'=>$failed===0,'generated_at'=>now()->toIso8601String(),'sample_strategy'=>'first, middle and last mapped row','checks'=>$checks,'totals'=>['checks'=>count($checks),'passed'=>collect($checks)->where('status','PASS')->count(),'failed'=>$failed,'skipped'=>collect($checks)->where('status','SKIP')->count(),'relationship_types'=>$relationshipChecks]];
    }

    private function supportedTables(): array
    {
        return ['users','categories','diagnoses','expense_categories','insurers','lens_options','referral_snippets','sms_templates','suppliers','patients','products','appointments','cashier_patient_clearances','consultations','refractions','carts','consultation_diagnosis','consultation_notes','lens_orders','expenses','sales','sale_items','payment_transactions','stock_movements','insurance_claims','quotations','quotation_items','purchase_orders','purchase_order_items','inventory_lots','branch_inventory_items','stock_transfers','stock_transfer_items','refund_logs','sale_adjustments','discount_approval_requests','clearance_revoke_logs','referrals','patient_documents','app_notifications','sms_logs','report_deliveries','staff_messages','login_logs','settings'];
    }

    public function rollback(LegacyImportBatch $batch): array
    {
        abort_if($batch->cutover_approved_at,422,'An approved cutover cannot be rolled back. Follow the production recovery procedure.');
        abort_unless($batch->committed_at&&!$batch->rolled_back_at,422,'This batch cannot be rolled back.');
        $summary=DB::transaction(function()use($batch){
            $clinicId=$batch->clinic_id;
            $batch->update(['clinic_id'=>null]);
            $summary=$this->deleteClinicRows($clinicId);
            $summary['deleted_user_ids']=$this->deleteUsersWithoutClinic($batch->result['created_user_ids']??[]);
            $this->deleteBatchRecords($batch);
            return $summary;
        });
        // Files go after the database commit. A storage outage must not undo or fail a rollback
        // that already succeeded, so each deletion is attempted independently and failures are reported.
        $summary['file_cleanup_errors']=$this->deleteFiles(array_merge($this->clinicFilePaths($summary['clinic_uuid']),$this->batchFilePaths($batch)));
        return $summary;
    }

    // Permanently deletes a clinic that has no import behind it (e.g. an empty duplicate created by hand),
    // together with the given accounts once they belong to no other clinic.
    public function deleteClinic(int $clinicId,array $userIds=[]): array
    {
        $blocker=$this->clinicDeletionBlocker($clinicId);
        abort_if($blocker!==null,422,$blocker);
        $summary=DB::transaction(function()use($clinicId,$userIds){
            $summary=$this->deleteClinicRows($clinicId);
            $summary['deleted_user_ids']=$this->deleteUsersWithoutClinic($userIds);
            return $summary;
        });
        $summary['file_cleanup_errors']=$this->deleteFiles($this->clinicFilePaths($summary['clinic_uuid']));
        return $summary;
    }

    // Only empty clinics may be deleted outright; anything with real data goes through rollback or offboarding.
    public function clinicDeletionBlocker(int $clinicId): ?string
    {
        $clinic=DB::table('clinics')->where('id',$clinicId)->first();
        if(!$clinic)return 'This clinic no longer exists.';
        if(LegacyImportBatch::where('clinic_id',$clinicId)->exists())return 'This clinic came from a legacy import. Roll the import back from Legacy Imports instead.';
        if($clinic->deployment_mode==='local')return 'This is an offline installation clinic and cannot be deleted here.';
        foreach(['patients'=>'patients','sales'=>'sales','consultations'=>'consultations'] as $table=>$label)
            if(Schema::hasTable($table)&&DB::table($table)->where('clinic_id',$clinicId)->exists())return "This clinic has {$label}. Use Cancellation & Clinic Offboarding instead.";
        return null;
    }

    // Accounts that belong to this clinic only, so deleting the clinic would leave them with nowhere to work.
    public function clinicOnlyAccounts(int $clinicId)
    {
        return DB::table('users')->join('clinic_user','clinic_user.user_id','=','users.id')->where('clinic_user.clinic_id',$clinicId)->where('users.is_platform_admin',false)
            ->whereNotExists(fn($q)=>$q->from('clinic_user as other')->whereColumn('other.user_id','users.id')->where('other.clinic_id','<>',$clinicId))
            ->orderBy('users.email')->get(['users.id','users.name','users.email']);
    }

    // Must run inside a transaction. Removes every row scoped to the clinic or its branches.
    private function deleteClinicRows(int $clinicId): array
    {
        $branchIds=DB::table('branches')->where('clinic_id',$clinicId)->pluck('id');$deleted=[];
        $clinicUuid=DB::table('clinics')->where('id',$clinicId)->value('uuid');
        // Nothing about a deleted clinic is kept; callers record one compact audit entry afterwards.
        $deleted['platform_audit_logs']=DB::table('platform_audit_logs')->where('clinic_id',$clinicId)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try{
            $schema=DB::getDatabaseName();
            $scopedColumns=DB::table('information_schema.columns')->selectRaw('TABLE_NAME as table_name, COLUMN_NAME as column_name')->where('table_schema',$schema)->whereIn('column_name',['clinic_id','branch_id'])->get();
            $scopedTables=$scopedColumns->pluck('table_name')->unique();
            // Child rows without their own clinic/branch scope (e.g. consultation_diagnosis) rely on cascades, which are off here, so delete them through their parents first.
            $childKeys=DB::table('information_schema.key_column_usage')->selectRaw('TABLE_NAME as table_name, COLUMN_NAME as column_name, REFERENCED_TABLE_NAME as parent_table, REFERENCED_COLUMN_NAME as parent_column')->where('table_schema',$schema)->whereNotNull('referenced_table_name')->get()
                ->filter(fn($key)=>!$scopedTables->contains($key->table_name)&&$scopedTables->contains($key->parent_table)&&!in_array($key->table_name,['users','clinics','branches'],true)&&!in_array($key->parent_table,['clinics','branches','legacy_import_batches','platform_audit_logs'],true));
            foreach($childKeys as $key){
                $parentHasClinic=$scopedColumns->contains(fn($c)=>$c->table_name===$key->parent_table&&$c->column_name==='clinic_id');
                $parentIds=DB::table($key->parent_table)->when($parentHasClinic,fn($q)=>$q->where('clinic_id',$clinicId),fn($q)=>$q->whereIn('branch_id',$branchIds))->select($key->parent_column);
                $count=DB::table($key->table_name)->whereIn($key->column_name,$parentIds)->delete();
                if($count)$deleted[$key->table_name]=($deleted[$key->table_name]??0)+$count;
            }
            foreach($scopedColumns->where('column_name','clinic_id')->pluck('table_name') as $table)if(!in_array($table,['clinics','legacy_import_batches','platform_audit_logs'],true)){$count=DB::table($table)->where('clinic_id',$clinicId)->delete();if($count)$deleted[$table]=($deleted[$table]??0)+$count;}
            foreach($scopedColumns->where('column_name','branch_id')->pluck('table_name') as $table)if(!in_array($table,['branches','platform_audit_logs'],true)){$count=DB::table($table)->whereIn('branch_id',$branchIds)->delete();if($count)$deleted[$table]=($deleted[$table]??0)+$count;}
            $deleted['branches']=DB::table('branches')->where('clinic_id',$clinicId)->delete();DB::table('clinics')->where('id',$clinicId)->delete();$deleted['clinics']=1;
        }finally{DB::statement('SET FOREIGN_KEY_CHECKS=1');}
        ksort($deleted);
        return ['clinic_id'=>$clinicId,'clinic_uuid'=>$clinicUuid,'deleted'=>$deleted];
    }

    private function deleteUsersWithoutClinic(array $userIds): array
    {
        $deletedUsers=[];
        foreach($userIds as $id)if(!DB::table('clinic_user')->where('user_id',$id)->exists()&&!DB::table('users')->where('id',$id)->value('is_platform_admin')){DB::table('users')->where('id',$id)->delete();$deletedUsers[]=$id;}
        // Spatie role rows and sessions have no foreign key to users, so they are cleared explicitly.
        if($deletedUsers){foreach(['model_has_roles','model_has_permissions'] as $table)DB::table($table)->where('model_type',\App\Models\User::class)->whereIn('model_id',$deletedUsers)->delete();DB::table('sessions')->whereIn('user_id',$deletedUsers)->delete();}
        return $deletedUsers;
    }

    private function clinicFilePaths(?string $clinicUuid): array
    {
        return $clinicUuid?[['local','clinics/'.$clinicUuid,'dir'],['public','clinics/'.$clinicUuid,'dir']]:[];
    }

    private function deleteFiles(array $paths): array
    {
        $errors=[];
        foreach($paths as [$disk,$path,$kind]){
            try{$this->deletePath($disk,$path,$kind);}
            catch(\Throwable $e){report($e);$errors[]="{$disk}:{$path}";}
        }
        return $errors;
    }

    // Permanently removes a batch that did not produce a live clinic (discarded, failed or never committed).
    public function purge(LegacyImportBatch $batch): void
    {
        abort_if($batch->committed_at&&!$batch->rolled_back_at,422,'A committed import must be rolled back, not deleted.');
        abort_if(in_array($batch->status,['queued','importing'],true),422,'An import that is queued or running cannot be deleted.');
        DB::transaction(fn()=>$this->deleteBatchRecords($batch));
        $this->deleteBatchFiles($batch);
    }

    private function deleteBatchRecords(LegacyImportBatch $batch): void
    {
        foreach(['old_values','new_values'] as $column)DB::table('platform_audit_logs')->whereRaw("JSON_EXTRACT(`{$column}`,'$.batch_id') = ?",[$batch->id])->delete();
        DB::table('legacy_import_batches')->where('id',$batch->id)->delete();
    }

    private function deleteBatchFiles(LegacyImportBatch $batch): void
    {
        foreach($this->batchFilePaths($batch) as [$disk,$path,$kind])$this->deletePath($disk,$path,$kind);
    }

    private function deletePath(string $disk,string $path,string $kind): void
    {
        $kind==='dir'?Storage::disk($disk)->deleteDirectory($path):Storage::disk($disk)->delete($path);
    }

    private function batchFilePaths(LegacyImportBatch $batch): array
    {
        return array_values(array_filter([
            $batch->stored_path?['local',$batch->stored_path,'file']:null,
            ['local','legacy-import-evidence/'.$batch->uuid,'dir'],
        ]));
    }
}
