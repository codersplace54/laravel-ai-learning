<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KyaMaster extends Model
{
    protected $table = 'kya_master';

    protected $fillable = [
        'serial_no',
        'sector',
        'risk_category',
        'industry_sector',
        'question',
        'department',
        'approval_name',
        'stage_of_business',
        'sla_days',
        'steps',
        'documents_required',
        'fees',
        'legal_provision',
        'more_info',
    ];

}
