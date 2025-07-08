<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unique();
            $table->string('unit_name');
            $table->string('unit_address');
            $table->string('pin_no');
            $table->string('post_office')->nullable();
            $table->string('contact_no')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            $table->string('unit_location_district');
            $table->string('unit_location_subdivision');
            $table->string('unit_location_police_station');
            $table->enum('unit_location_land_type', ['Industrial Estate', 'Panchayat', 'Municipality']);
            $table->enum('unit_location_area_type', ['Urban', 'Rural']);

            $table->string('unit_location_estate_name')->nullable();
            $table->string('unit_location_plot_no')->nullable();

            $table->string('unit_location_block')->nullable();
            $table->string('unit_location_gram_panchayat')->nullable();

            $table->string('unit_location_municipality')->nullable();
            $table->string('unit_location_ward_no')->nullable();

            $table->string('unit_location_planning_area')->nullable();

            $table->string('land_record_details_revenue_circle')->nullable();
            $table->string('land_record_details_tehasil')->nullable();
            $table->string('land_record_details_revenue_mouza')->nullable();
            $table->string('land_record_details_khatian_number_new')->nullable();
            $table->string('land_record_details_plot_number_cs_sabek')->nullable();
            $table->string('land_record_details_plot_number_rs_hal')->nullable();
            $table->enum('land_record_details_classification_of_land', ['Agriculture', 'Commercial', 'Residential', 'Industrial'])->nullable();
            $table->string('land_record_details_land_area')->nullable();
            $table->enum('land_record_details_unit', ['Sq Mtr', 'Acre', 'Hector'])->nullable();
            
            $table->string('construction_details_load_bearing_in_sq_mtr')->nullable();
            $table->string('construction_details_rcc_building_in_sq_mtr')->nullable();
            $table->string('construction_details_others_construction')->nullable();
            $table->string('construction_details_sanitary_latrine_count')->nullable();
            $table->string('construction_details_boundary_wall_in_mtr')->nullable();
            $table->string('construction_details_power_supply_agency_at_the_factory')->nullable();

            $table->string('investment_details_value_of_land_as_per_sale_deed')->nullable();
            $table->string('investment_details_value_of_building')->nullable();
            $table->string('investment_details_value_of_plant_machinery_or_service_equipment')->nullable();
            $table->string('investment_details_total_project_cost')->nullable();

            $table->string('employment_details_worker_men_count')->nullable();
            $table->string('employment_details_worker_women_count')->nullable();
            $table->string('employment_details_management_staff_count')->nullable();
            $table->string('employment_details_others_count')->nullable();
            $table->string('employment_details_total_employment')->nullable();

            $table->string('annual_turnover');
            $table->enum('category_of_enterprise', ['Micro', 'Small', 'Medium', 'Large'])->nullable();
            $table->string('working_session')->nullable();
            $table->longText('product_manufacturing_process')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_details');
    }
};
