<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncentiveWorkflowHistory extends Model
{
    protected $fillable = [
        'application_id',
        'from_status',
        'to_status',
        'action',
        'action_taken_by',
        'remarks',
        'meta',
        'action_taken_at',
        'review_file',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected $casts = [
        'action_taken_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
