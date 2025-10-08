<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceApprovalFlow extends Model
{

    protected $fillable = [
        "id",
        'service_id',
        'step_number',
        'step_type',
        'department_id',
        'hierarchy_level',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
