<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserKya extends Model
{
    protected $table = 'user_kya';
    protected $fillable = ['user_id', 'data'];
}
