<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A kind of leave at a branch: its name, the working days a person may take in a calendar year
 * (null = no limit) and whether it is paid. Until a branch saves its own, the built-in four apply.
 */
class AttendanceLeaveType extends Model
{
    use BelongsToBranch;

    /** code => [name, days a year]. Annual leave starts at the 15 working days of Ghana's Labour Act. */
    public const DEFAULTS = ['annual' => ['Annual leave', 15], 'sick' => ['Sick leave', null], 'permission' => ['Permission', null], 'other' => ['Other', null]];

    protected $fillable = ['code', 'name', 'days_per_year', 'is_paid', 'is_active'];

    protected $casts = ['days_per_year' => 'float', 'is_paid' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = ['is_paid' => true, 'is_active' => true];

    protected static function booted(): void
    {
        static::saved(fn (self $type) => self::flush($type->branch_id));
        static::deleted(fn (self $type) => self::flush($type->branch_id));
    }

    /** The branch's leave types by code: its saved ones, else the built-in four (not saved). */
    public static function options(): Collection
    {
        // Read once per request or queued job, for each branch.
        $key = self::cacheKey(app(TenantContext::class)->branchId());
        if (! app()->bound($key)) {
            app()->scoped($key, function () {
                $saved = static::query()->orderBy('id')->get();

                return ($saved->isNotEmpty() ? $saved : collect(self::DEFAULTS)->map(fn ($type, $code) => new self(['code' => $code, 'name' => $type[0], 'days_per_year' => $type[1]])))
                    ->keyBy('code');
            });
        }

        return app($key);
    }

    private static function cacheKey(?int $branchId): string
    {
        return 'attendance.leave-types.'.($branchId ?? 0);
    }

    /** The types someone can be given now. */
    public static function offered(): Collection
    {
        return self::options()->filter(fn (self $type) => $type->is_active);
    }

    /** Save the built-in four for this branch, so they can be edited. */
    public static function ensureSaved(): void
    {
        if (static::query()->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $code => [$name, $days]) {
            static::create(['code' => $code, 'name' => $name, 'days_per_year' => $days]);
        }
    }

    /** A code for a new type, from its name and not yet used at the branch. */
    public static function codeFor(string $name): string
    {
        $base = substr(Str::slug($name, '_') ?: 'leave', 0, 17);
        $code = $base;
        for ($n = 2; static::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }

    public static function flush(?int $branchId = null): void
    {
        foreach (array_unique([$branchId, app(TenantContext::class)->branchId(), null]) as $id) {
            app()->forgetInstance(self::cacheKey($id));
        }
    }
}
