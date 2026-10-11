<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A day the branch is closed: nobody is absent on it. */
class AttendanceHoliday extends Model
{
    use BelongsToBranch;

    protected $fillable = ['date', 'name'];

    protected $casts = ['date' => 'date'];
}
