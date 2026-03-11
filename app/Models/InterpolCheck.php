<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterpolCheck extends Model
{
    protected $fillable = [
        'unique_reference_id',
        'first_name',
        'surname',
        'date_of_birth',
        'interpol_nominal_matched',
        'checked_at',
        'raw_payload',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'checked_at' => 'datetime',
        'raw_payload' => 'array',
    ];
}
