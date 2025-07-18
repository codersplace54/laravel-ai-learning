<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineOfActivity extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'thrust_sector',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
