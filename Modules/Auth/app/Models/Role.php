<?php

namespace Modules\Auth\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Auth\Database\Factories\RoleFactory;

class Role extends Model
{
    use HasFactory;


    protected $table = 'roles';


    protected $fillable = [
        'id',
        'code',
        'role',
        'created_at',
        'updated_at'
    ];


    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
