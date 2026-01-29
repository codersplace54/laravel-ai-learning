<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestingFacility extends Model
{
    protected $fillable = [
        'id',
        'institution_name',
        'organization_type',
        'lab_name',
        'district',
        'address',
        'ownership',
        'sector',
        'facilities_available',
        'facilities_not_available',
        'key_equipment',
        'manpower',
        'accreditation',
        'msme_access',
        'charges',
        'turnaround_time',
        'contact_person',
        'phone',
        'email',
    ];
}
