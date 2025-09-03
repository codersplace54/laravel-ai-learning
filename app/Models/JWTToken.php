<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;

class JWTToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'user_id',
        'token',
        'ip_address',
        'user_agent',
        'expires_at',
        'last_activity_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime:Y-m-d H:i:s',
        'last_activity_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
