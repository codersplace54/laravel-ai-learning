<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentApplication extends Model
{
    protected $fillable = [
        'user_id',
        'aadhaar_or_business_id',
        'registered_office_address',
        'communication_address',
        'sector',
        'legal_structure',
        'registration_no',
        'year_of_establishment',
        'gstin',
        'industry_category',
        'brief_proposal',
        'project_title',
        'sub_sector',
        'investment_value',
        'employment_to_be_generated',
        'nature_of_activity',
        'proposed_land_type',
        'area_required',
        'location_lat',
        'location_lng',
        'location_address',
        'connectivity_needs',
        'other_requirements',
        'query_id',
        'status',
        'admin_note',
        'heard_from',
    ];


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
