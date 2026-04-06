<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicNotification extends Model
{
    protected $fillable = [
        'id',
        'display_order',
        'message',
        'attachment',
        'upload_date',
        'valid_till',
        'status',
        'featured',
        'link',
        'is_banner',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
