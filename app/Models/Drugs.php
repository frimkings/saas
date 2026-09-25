<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;

class Drugs extends Model
{


    use HasFactory, BelongsToClinic;


    protected $fillable = [
        'name',
        'quantity',
        'price',
        'expiryDate'

    ];
}
