<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagementDetails extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'owner_details_name',
        'owner_details_fathers_name',
        'owner_details_residential_address',
        'owner_details_police_station',
        'owner_details_pin',
        'owner_aadhar_no',
        'owner_details_mobile',
        'owner_details_alternate_mobile',
        'owner_details_aadhar_no',
        'owner_details_status',
        'owner_details_email',
        'owner_details_dob',
        'owner_details_social_status',
        'owner_details_is_differently_abled',
        'owner_details_is_women_entrepreneur',
        'owner_details_is_minority',
        'owner_details_photo',

        'manager_details_name',
        'manager_details_fathers_name',
        'manager_details_residential_address',
        'manager_details_police_station',
        'manager_details_pin',
        'manager_details_mobile',
        'manager_details_aadhar_no',
        'manager_details_dob',
        'manager_details_photo',

        'signature_authorization_of_owner',
        'factory_occupiers_signature',
        'factory_managers_signature',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

}
