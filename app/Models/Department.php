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
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function services()
    {
        return $this->hasMany(ServiceMaster::class, 'department_id', 'id');
    }
}
