<?php

namespace App\Models;

use App\Services\LicenseService;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

class AuditTrail extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;
    protected $fillable = [
        'user_id',
        'patient_id',
        'auditable_type',
        'auditable_id',
        'event',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    // Fix #8: $force=true bypasses the license gate for security-critical events
    // (bulk deletes, exports, license changes) so a DB-level license downgrade
    // cannot be used to erase evidence of an attack.
    public static function record(string $event, string $description, $auditable = null, array $oldValues = [], array $newValues = [], ?int $patientId = null, bool $force = false): ?self
    {
        if (!$force && !LicenseService::has(Feature::AUDIT_TRAIL)) {
            return null;
        }

        return self::create([
            'user_id'        => Auth::id(),
            'patient_id'     => $patientId,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id'   => $auditable->id ?? null,
            'event'          => $event,
            'description'    => $description,
            'old_values'     => $oldValues ?: null,
            'new_values'     => $newValues ?: null,
            'ip_address'     => request()?->ip(),
            // Fix #13: strip tags + truncate; user_agent is attacker-controlled
            'user_agent'     => substr(strip_tags((string) request()?->userAgent()), 0, 500),
        ]);
    }

    /**
     * Save a model and record what changed as "{event}_created" or "{event}_updated", with
     * the old and new values. Nothing is recorded when nothing changed.
     */
    public static function recordSave(Model $model, string $event, string $subject, ?int $patientId = null): void
    {
        $isNew = ! $model->exists;
        $changes = array_diff_key($model->getDirty(), array_flip(['created_at', 'updated_at']));
        $before = $isNew ? [] : array_intersect_key($model->getOriginal(), $changes);
        $model->save();
        if ($isNew || $changes) {
            self::record($event.($isNew ? '_created' : '_updated'), ($isNew ? 'Added ' : 'Changed ').$subject, $model, $before, $changes, $patientId);
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }
}
