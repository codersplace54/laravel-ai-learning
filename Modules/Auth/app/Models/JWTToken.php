<?php

namespace Modules\Auth\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\User\Models\User;

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
        'created_at',
        'updated_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
