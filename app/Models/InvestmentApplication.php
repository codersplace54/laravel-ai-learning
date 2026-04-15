<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'remark',
        'action_taken_by',
        'heard_from',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actionTaker()
    {
        return $this->belongsTo(User::class, 'action_taken_by');
    }

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'investment_application_departments', 'investment_application_id', 'department_id')
            ->withTimestamps();
    }
}
