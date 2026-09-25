<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

use Illuminate\Database\Eloquent\Model;

class AuditTrailArchive extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;
    protected $table = 'audit_trails_archive';

    public $timestamps = false;

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
