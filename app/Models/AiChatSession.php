<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatSession extends Model
{
    protected $fillable = [
        'user_id',
        'active_application_id',
        'active_service_id',
        'last_intent',
        'title',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
