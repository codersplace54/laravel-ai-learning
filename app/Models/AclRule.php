<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AclRule extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'department_id',
        'service_id',
        'role_id',
        'role_code',
        'district',
        'sub_division',
        'ulb',
        'gp_vc_mc',
        'created_at',
        'updated_at',
    ];
}
