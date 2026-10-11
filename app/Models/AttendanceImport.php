<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** An attendance report file uploaded from the terminal, and the days it covers. */
class AttendanceImport extends Model
{
    use BelongsToBranch;

    protected $fillable = ['file_name', 'period_start', 'period_end', 'exported_at', 'staff_count', 'new_punches', 'uploaded_by'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'exported_at' => 'datetime',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
