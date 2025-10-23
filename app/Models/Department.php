<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Department extends Model
{
    use HasFactory;


    protected $table = 'departments';


    protected $fillable = [
        'id',
        'name',
        'details',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function feedbacks()
    {
        return $this->hasMany(UserFeedback::class, 'department_id');
    }

    public function services()
    {
        return $this->hasMany(ServiceMaster::class, 'department_id', 'id');
    }

    public function applications()
    {
        return $this->hasManyThrough(
            UserServiceApplication::class,
            ServiceMaster::class,
            'department_id',
            'service_id',
            'id',
            'id'
        );
    }

    public function users()
    {
        return $this->hasMany(DepartmentUser::class, 'department_id');
    }
}
