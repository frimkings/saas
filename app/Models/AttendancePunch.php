<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One punch, in the branch's own clock time: from the terminal, an uploaded report, or entered
 * by a manager as a correction. A punch removed as a mistake is kept and marked voided; every
 * ordinary query leaves voided punches out (withVoided() brings them back for the history).
 */
class AttendancePunch extends Model
{
    use BelongsToBranch;

    public const SOURCES = ['terminal' => 'Terminal', 'report' => 'Uploaded report', 'manual' => 'Entered by a manager', 'phone' => 'Their phone'];

    public $timestamps = false;

    protected $fillable = ['attendance_staff_id', 'punched_at', 'attendance_import_id', 'source', 'latitude', 'longitude', 'accuracy', 'distance', 'created_by', 'reason', 'replaces_id',
        'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['punched_at' => 'datetime', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope('live', fn (Builder $query) => $query->whereNull($query->qualifyColumn('voided_at')));
    }

    public function scopeWithVoided(Builder $query): Builder
    {
        return $query->withoutGlobalScope('live');
    }

    public function staff()
    {
        return $this->belongsTo(AttendanceStaff::class, 'attendance_staff_id');
    }

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
