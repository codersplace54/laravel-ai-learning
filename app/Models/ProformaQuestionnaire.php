<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProformaQuestionnaire extends Model
{
    protected $fillable = [
        'proforma_id',
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
        'field_rules',
        'upload_rule'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
