<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentUser extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'department_id',
        'designation',
        'block_id',
        'subdivision_id',
        'district_id',
        'hierarchy_level',
        'is_active',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
