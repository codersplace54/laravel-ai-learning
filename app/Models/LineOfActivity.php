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

}
