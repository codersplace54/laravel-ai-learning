<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatMessage extends Model
{
    protected $fillable = [
        'ai_chat_session_id',
        'user_id',
        'role',
        'message',
        'intent',
        'answer_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
