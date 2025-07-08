<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitDetail extends Model
{
    use HasFactory;

    protected $table = 'unit_details';

    protected $fillable = [
        'id',
        'user_id',
        'unit_name',
        'unit_address',
        'pin_no',
        'post_office',
        'contact_no',
        'fax',
        'email',
        'website',

        'unit_location_district',
        'unit_location_subdivision',
        'unit_location_police_station',
        'unit_location_land_type',
        'unit_location_area_type',
        'unit_location_estate_name',
        'unit_location_plot_no',
        'unit_location_block',
        'unit_location_gram_panchayat',
        'unit_location_municipality',
        'unit_location_ward_no',
        'unit_location_planning_area',

        'land_record_details_revenue_circle',
        'land_record_details_tehasil',
        'land_record_details_revenue_mouza',
        'land_record_details_khatian_number_new',
        'land_record_details_plot_number_cs_sabek',
        'land_record_details_plot_number_rs_hal',
        'land_record_details_classification_of_land',
        'land_record_details_land_area',
        'land_record_details_unit',

        'construction_details_load_bearing_in_sq_mtr',
        'construction_details_rcc_building_in_sq_mtr',
        'construction_details_others_construction',
        'construction_details_sanitary_latrine_count',
        'construction_details_boundary_wall_in_mtr',
        'construction_details_power_supply_agency_at_the_factory',

        'investment_details_value_of_land_as_per_sale_deed',
        'investment_details_value_of_building',
        'investment_details_value_of_plant_machinery_or_service_equipment',
        'investment_details_total_project_cost',

        'employment_details_worker_men_count',
        'employment_details_worker_women_count',
        'employment_details_management_staff_count',
        'employment_details_others_count',
        'employment_details_total_employment',

        'annual_turnover',
        'category_of_enterprise',
        'working_session',
        'product_manufacturing_process',
        
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
