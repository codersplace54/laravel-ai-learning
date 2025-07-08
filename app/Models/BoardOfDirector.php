<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoardOfDirector extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'name',
        'permanent_address',
        'mobile_number',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
