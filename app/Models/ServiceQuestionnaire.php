<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceQuestionnaire extends Model
{
    protected $fillable = [
        'id',
        'service_id',
        'question_label',
        'question_type',
        'is_required',
        'options',
        'default_value',
        'default_source_table',
        'default_source_column',
        'display_order',
        'group_label',
        'display_width',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
