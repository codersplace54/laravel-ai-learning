<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationWorkflowHistory extends Model
{

    protected $table = 'application_workflow_history';

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
        'status_file',
        'external_status',
        'external_payment_amount',
        'external_payment_status',
        'external_noc_url',
        'external_noc_file',
        'source',

        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function actionTaker()
    {
        return $this->belongsTo(User::class, 'action_taken_by');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
