<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tripura extends Model
{
    protected $table = "tripura";

    protected $fillable = [
        'id',
        'district',
        'subdivision',
        'ulb',
        'ward',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
