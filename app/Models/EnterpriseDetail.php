<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EnterpriseDetail extends Model
{
    use HasFactory;

    protected $table = 'enterprise_details';


    protected $fillable = [
        'id',
        'user_id',
        'constitution_of_enterprise',
        'enterprise_name',
        'business_pan_no',
        'enterprise_address',
        'enterprises_registered_address',
        'habitation_area_building',
        'pin',
        'post_office',
        'police_station',
        'authorized_representative_name',
        'authorized_representative_designation',
        'authorized_representative_aadhar_no',
        'authorized_representative_mobile_no',
        'authorized_representative_email_id',
        'authorized_representative_alternate_mobile_no',
        'authorized_representative_phone_no',
        'authorized_representative_gstNumber',
        'authorized_representative_cin_number',
        'proposal_for',
        'proposed_date_of_commissioning',
        'created_at',
        'updated_at'
    ];


    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
