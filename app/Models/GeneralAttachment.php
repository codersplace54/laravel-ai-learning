<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralAttachment extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'general_self_certification_form',
        'do_you_have_trees_in_the_land_for_industry',
        'type_of_tree',
        'self_certificate_format_3A',
        'tree_registration_certificate',
        'owner_pan_pdf',
        'owner_pan_number',
        'owner_aadhar_pdf',
        'owner_aadhar_number',
        'udyog_aadhar',
        'udyog_aadhar_number',
        'gst_certificate_pdf',
        'gst_number',
        'udyog_aadhar_registration_date',
        'combined_plan_document',
        'unit_land_details_pdf',
        'unit_registaration_details_pdf',
        'unit_property_tax_clearance_certificate_pdf',
        'unit_process_flow_chart_diagram_or_write_up_pdf',
        'detailed_project_report_pdf',
        'other_supporting_docuement1_pdf',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
