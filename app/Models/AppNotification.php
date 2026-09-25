<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class AppNotification extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;

    protected static function booted(): void
    {
        static::addGlobalScope('active_branch', function (Builder $builder): void {
            if (! config('tenancy.enabled')) {
                return;
            }

            $branchId = app(TenantContext::class)->branchId();
            $branchId === null
                ? $builder->whereRaw('1 = 0')
                : $builder->where($builder->qualifyColumn('branch_id'), $branchId);
        });
    }

    protected $table = 'app_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'icon',
        'icon_color',
        'action_url',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
