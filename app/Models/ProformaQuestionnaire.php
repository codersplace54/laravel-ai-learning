<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProformaQuestionnaire extends Model
{
    protected $fillable = [
        'proforma_id',
        'question_label',
        'is_claim',
        'claim_percentage',
        'claim_per_unit',
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
        'upload_rule',
        'display_rule',
        'created_by',
        'updated_by',
        'sample_format',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
