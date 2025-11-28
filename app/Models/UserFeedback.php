<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeedback extends Model
{
    protected $table = 'user_feedbacks';
    protected $fillable = [
        'id',
        'user_id',
        'service_id',
        'department_id',
        'satisfaction',
        'feedback',
        'suggestions',
        'created_at',
        'updated_at',
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
