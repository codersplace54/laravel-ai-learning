<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestingFacilityCapability extends Model
{
    protected $fillable = [
        'id',
        'product_material',
        'test_parameter',
        'test_method',
        'group_name',
        'sub_group_name',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s'
    ];
}
