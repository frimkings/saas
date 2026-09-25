<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

use Illuminate\Database\Eloquent\Model;

class SmsLogArchive extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;
    protected $table = 'sms_logs_archive';

    public $timestamps = false;

    protected $casts = [
        'success'    => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
