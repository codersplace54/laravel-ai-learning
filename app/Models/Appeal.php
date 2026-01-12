<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appeal extends Model
{
    protected $fillable = [
        'id',
        'application_id',
        'user_id',
        'appeal_file',
        'remarks_from_user',
        'department_id',
        'status',
        'remarks_by_dept',
        'dept_file',
        'created_at',
        'updated_at'

    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function application()
    {
        return $this->belongsTo(UserServiceApplication::class, 'application_id');
    }
}
