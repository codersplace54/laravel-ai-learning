<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncentiveWorkflowHistory extends Model
{
    protected $fillable = [
        'application_id',
        'from_status',
        'to_status',
        'action_taken_by',
        'remarks',
        'review_file',
        'action_taken_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'action_taken_by');
    }

    protected $casts = [
        'action_taken_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
