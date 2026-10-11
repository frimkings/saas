<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** One punch on the terminal, in the branch's own clock time. */
class AttendancePunch extends Model
{
    use BelongsToBranch;

    public $timestamps = false;

    protected $fillable = ['attendance_staff_id', 'punched_at', 'attendance_import_id'];

    protected $casts = ['punched_at' => 'datetime'];
}
