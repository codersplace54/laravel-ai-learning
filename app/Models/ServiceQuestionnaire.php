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
        'validation_required',
        'validation_rule',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'sample_format',
        'is_section',
        'section_name',
        'upload_rule',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
