<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorQuery extends Model
{
    protected $fillable = [
        'query_topic',
        'company_name',
        'company_address',
        'city',
        'state',
        'present_activities',
        'area_of_interest',
        'department_id',
        'full_name',
        'email',
        'mobile',
        'query_summary',
        'query_details',
        'attachment',
        'reference_id',
        'status',
        'admin_note',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
