<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $fillable = [
        'mobile_no',
        'code',
        'expires_at',
        'is_verified'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
