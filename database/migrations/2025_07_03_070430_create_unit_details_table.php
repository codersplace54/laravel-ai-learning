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
            $table->bigInteger('user_id')->unique()->nullable();
            $table->string('unit_name');
            $table->string('unit_address');
            $table->string('district');
            $table->string('subdivision');
            $table->string('block')->nullable();
            $table->string('police_station');
            $table->string('post_office');
            $table->string('pin_no');
            $table->string('contact_no');
            $table->string('fax')->nullable();
            $table->string('email');
            $table->string('website');
            $table->string('land_type');
            $table->string('area_type');
            $table->string('planning_area');
            $table->string('estate_name');
            $table->string('plot_no');
            $table->string('khatian_no_new');
            $table->string('plot_no_cs_sabek');
            $table->string('plot_no_rs_hal');
            $table->string('classification_of_land');
            $table->string('land_area');
            $table->string('load_bearing_building_sq_mtr');
            $table->string('rcc_building_sq_mtr');
            $table->string('others_construction');
            $table->integer('sanitary_latrine_count');
            $table->string('boundary_wall_mtr');
            $table->string('power_supply_agency');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_details');
    }
};
