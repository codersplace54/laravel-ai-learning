<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('general_attachments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('general_self_certification_form');
            $table->enum('do_you_have_trees_in_the_land_for_industry', ['YES', 'NO']);
            $table->enum('type_of_tree', ['EXEMPTED', 'NON_EXEMPTED'])->nullable();
            $table->string('self_certificate_format_3A')->nullable();
            $table->string('tree_registration_certificate')->nullable();
            $table->string('owner_pan_pdf');
            $table->string('owner_pan_number');
            $table->string('owner_aadhar_pdf')->nullable();
            $table->string('owner_aadhar_number')->nullable();
            $table->string('udyog_aadhar')->nullable();
            $table->string('udyog_aadhar_number')->nullable();
            $table->string('gst_certificate_pdf')->nullable();
            $table->string('gst_number')->nullable();
            $table->date('udyog_aadhar_registration_date')->nullable();
            $table->string('combined_plan_document')->nullable()->comment('combined_building_plan_including_all_floor_plans_and_combined_site_plan');
            $table->string('unit_land_details_pdf')->nullable();
            $table->string('unit_registaration_details_pdf')->nullable();
            $table->string('unit_property_tax_clearance_certificate_pdf')->nullable();
            $table->string('unit_process_flow_chart_diagram_or_write_up_pdf')->nullable();
            $table->string('detailed_project_report_pdf')->nullable();
            $table->string('other_supporting_docuement1_pdf')->nullable();
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('general_attachments');
    }
};
