<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelVerificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'passport_suffix',
        'nationality',
        'user_type',
        'ip_address',
        'status',
        'authorization_type',
        'eta_number',
        'visa_reference',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}

