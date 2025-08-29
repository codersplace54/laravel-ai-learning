<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationWorkflowAssignment extends Model
{
    protected $fillable = [
        'id',
        'application_id',
        'service_id',
        'step_number',
        'step_type',
        'department_id',
        'hierarchy_level',
        'assigned_to_group',
        'status',
        'action_taken_by',
        'action_taken_at',
        'remarks',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function actionTaker()
    {
        return $this->belongsTo(User::class, 'action_taken_by');
    }
}
