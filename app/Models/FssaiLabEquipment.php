<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FssaiLabEquipment extends Model
{
    protected $fillable = [
        'id',
        'uid',
        'equipment_name',
        'serial_no',
        'model',
        'make',
        'year_of_make',
        'range_accuracy',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
