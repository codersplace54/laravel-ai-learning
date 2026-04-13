<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappLog extends Model
{
    protected $table = 'whatsapp_logs';

    protected $fillable = ['user_id', 'template_name'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
