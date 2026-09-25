<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;

class Diagnosis extends Model
{
    use HasFactory, BelongsToClinic;

    protected $fillable = ['name'];


    public function consultations()
{
    return $this->belongsToMany(Consultations::class);
}
}
