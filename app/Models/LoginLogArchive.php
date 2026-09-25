<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

use Illuminate\Database\Eloquent\Model;

class LoginLogArchive extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;
    protected $table = 'login_logs_archive';

    public $timestamps = false;

    protected $casts = [
        'login_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
