<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeedback extends Model
{
    protected $table = 'user_feedbacks';
    protected $fillable = [
        'id',
        'ticket_id',
        'user_id',
        'service_id',
        'department_id',
        'satisfaction',
        'feedback',
        'suggestions',
        'resolved_at',
        'remark',
        'escalated',
        'status',
        'resolved_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'escalated'   => 'boolean',
    ];

    public function service()
    {
        return $this->belongsTo(ServiceMaster::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
