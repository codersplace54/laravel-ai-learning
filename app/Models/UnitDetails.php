<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitDetails extends Model
{
        protected $fillable = [
        'id',
        'user_id',
        'unit_name',
        'unit_address',
        'district',
        'subdivision',
        'block',
        'police_station',
        'post_office',
        'pin_no',
        'contact_no',
        'fax',
        'email',
        'website',
        'land_type',
        'area_type',
        'planning_area',
        'estate_name',
        'plot_no',
        'khatian_no_new',
        'plot_no_cs_sabek',
        'plot_no_rs_hal',
        'classification_of_land',
        'land_area',
        'load_bearing_building_sq_mtr',
        'rcc_building_sq_mtr',
        'others_construction',
        'sanitary_latrine_count',
        'boundary_wall_mtr',
        'power_supply_agency',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
