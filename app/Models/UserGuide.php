<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's progress on one guided tour or setup checklist in one clinic (see App\Support\Guides\Guides). */
class UserGuide extends Model
{
    public const DONE = 'done';
    public const SKIPPED = 'skipped';
    public const HIDDEN = 'hidden';

    protected $fillable = ['user_id', 'clinic_id', 'guide', 'version', 'status'];

    protected $casts = ['version' => 'integer'];
}
