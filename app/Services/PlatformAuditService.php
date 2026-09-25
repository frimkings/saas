<?php
namespace App\Services;
use App\Models\PlatformAuditLog;
class PlatformAuditService {
    public function record(string $action, ?int $clinicId=null, array $old=[], array $new=[], ?string $reason=null): void {
        PlatformAuditLog::create(['user_id'=>auth()->id(),'clinic_id'=>$clinicId,'action'=>$action,'old_values'=>$old ?: null,'new_values'=>$new ?: null,'reason'=>$reason,'ip_address'=>request()->ip()]);
    }
}
