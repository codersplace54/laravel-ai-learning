<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationWorkflowHistory extends Model
{
    protected $fillable = [
        'id',
        'application_id',
        'service_id',
        'step_number',
        'step_type',
        'department_id',
        'hierarchy_level',
        'action_taken_by',
        'action_taken_at',
        'status',
        'remarks',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
