<?php

namespace App\Models\Concerns;

/** A financial record that belongs to one business line (clinic or optical). */
trait HasBusinessLine
{
    public function scopeBusinessLine($query, string $line)
    {
        return $query->where($query->qualifyColumn('business_line'), $line);
    }
}
