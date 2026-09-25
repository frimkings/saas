<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ImportNaameraClinic extends Command
{
    protected $signature = 'tenant:import-naamera {--source=eyeclinic_import_naamera} {--admin-email=naamera@gmail.com}';
    protected $description = 'Import the staged Naamera legacy database into its tenant and main branch';

    public function handle(): int
    {
        if (Clinic::where('slug', 'naamera-eye-care')->exists()) {
            $this->error('Naamera Eye Care has already been imported.');
            return self::FAILURE;
        }

        config(['database.connections.legacy_import' => array_merge(config('database.connections.mysql'), ['database' => $this->option('source')])]);
        DB::purge('legacy_import');
        DB::connection('legacy_import')->select('select 1');

        DB::transaction(function () {
            $clinicId = DB::table('clinics')->insertGetId(['uuid'=>(string) Str::uuid(),'name'=>'Naamera Eye Care','slug'=>'naamera-eye-care','status'=>'active','deployment_mode'=>'hosted','default_timezone'=>'UTC','default_currency'=>'GHS','billing_email'=>'naamera@gmail.com','billing_phone'=>'0208355233/ 0506677968','created_at'=>now(),'updated_at'=>now()]);
            $branchId = DB::table('branches')->insertGetId(['uuid'=>(string) Str::uuid(),'clinic_id'=>$clinicId,'code'=>'MAIN','name'=>'Main Branch','timezone'=>'UTC','is_default'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
            $plan = DB::table('subscription_plans')->where('code','100')->orWhere('name','BASIC')->first();
            DB::table('clinic_subscriptions')->insert(['clinic_id'=>$clinicId,'subscription_plan_id'=>$plan->id,'status'=>'active','billing_interval'=>'monthly','renewal_mode'=>'manual','current_period_starts_at'=>now(),'current_period_ends_at'=>now()->addMonth(),'created_at'=>now(),'updated_at'=>now()]);

            $maps=[];
            $copy=function(string $table,array $foreign=[])use(&$maps,$clinicId,$branchId){
                if(!Schema::hasTable($table)) return;
                $columns=Schema::getColumnListing($table);
                foreach(DB::connection('legacy_import')->table($table)->orderBy('id')->get() as $old){
                    $row=array_intersect_key((array)$old,array_flip($columns)); unset($row['id']);
                    if(in_array('clinic_id',$columns,true))$row['clinic_id']=$clinicId;
                    if(in_array('branch_id',$columns,true))$row['branch_id']=$branchId;
                    foreach($foreign as $column=>$parent){if(isset($row[$column]))$row[$column]=$maps[$parent][$row[$column]]??null;}
                    $maps[$table][$old->id]=DB::table($table)->insertGetId($row);
                }
            };

            foreach(DB::connection('legacy_import')->table('users')->orderBy('id')->get() as $old){
                $email=$old->id===1?$this->option('admin-email'):$old->email;
                $id=DB::table('users')->where('email',$email)->value('id');
                if(!$id){$row=array_intersect_key((array)$old,array_flip(Schema::getColumnListing('users')));unset($row['id']);$row['email']=$email;if($old->id===1){$row['password']=Hash::make('password');$row['must_change_password']=1;}$id=DB::table('users')->insertGetId($row);}
                $maps['users'][$old->id]=$id;
            }

            foreach(['categories','diagnoses','expense_categories','insurers','lens_options','referral_snippets','sms_templates'] as $table)$copy($table);
            $copy('patients',['user_id'=>'users','insurer_id'=>'insurers']);
            $copy('products',['user_id'=>'users','category_id'=>'categories']);
            $copy('appointments',['patient_id'=>'patients','doctor_id'=>'users','user_id'=>'users']);
            $copy('cashier_patient_clearances',['user_id'=>'users','patient_id'=>'patients','service_id'=>'products']);
            $copy('expenses',['expense_category_id'=>'expense_categories','recorded_by'=>'users']);
            $copy('sales',['user_id'=>'users','patient_id'=>'patients','discount_approved_by'=>'users','refunded_by'=>'users']);
            $copy('sale_items',['sale_id'=>'sales','product_id'=>'products']);
            $copy('payment_transactions',['sale_id'=>'sales','collected_by'=>'users']);
            $copy('stock_movements',['product_id'=>'products','user_id'=>'users']);
            $copy('login_logs',['user_id'=>'users']);
            $copy('settings');

            $legacyRoles=DB::connection('legacy_import')->table('model_has_roles')->get();
            foreach($maps['users'] as $oldId=>$userId){
                $roleNames=$legacyRoles->where('model_id',$oldId)->map(fn($m)=>DB::connection('legacy_import')->table('roles')->where('id',$m->role_id)->value('name'))->filter();
                if($oldId===1)$roleNames=collect(['Super Admin']);
                $hasDefaultClinic=DB::table('clinic_user')->where('user_id',$userId)->where('clinic_id','<>',$clinicId)->where('status','active')->where('is_default',1)->exists();
                DB::table('clinic_user')->insert(['clinic_id'=>$clinicId,'user_id'=>$userId,'status'=>'active','clinic_role'=>$roleNames->first()??'Staff','is_default'=>$hasDefaultClinic?0:1,'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                DB::table('branch_user')->insert(['branch_id'=>$branchId,'user_id'=>$userId,'status'=>'active','is_default'=>1,'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                foreach($roleNames as $name){$role=Role::firstOrCreate(['name'=>$name,'guard_name'=>'web']);DB::table('branch_user_role')->insertOrIgnore(['branch_id'=>$branchId,'user_id'=>$userId,'role_id'=>$role->id,'created_at'=>now(),'updated_at'=>now()]);}
            }
        });

        $this->info('Naamera Eye Care imported successfully. Super Admin: '.$this->option('admin-email'));
        return self::SUCCESS;
    }
}
