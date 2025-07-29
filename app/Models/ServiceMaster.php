<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceMaster extends Model
{
    protected $fillable = [
        'id',
        'added_by',
        'department_id',
        'service_title_or_description',
        'noc_name',
        'noc_short_name',
        'noc_type',
        'noc_payment_type',
        'target_days',
        'has_input_form',
        'depends_on_services',
        'generate_id',
        'generate_pdf',
        'generated_id_format',
        'label_noc_date',
        'label_noc_doc',
        'label_noc_no',
        'label_valid_till',
        // 'show_letter_date',
        // 'show_letter_no',
        'show_valid_till',
        'auto_renewal',
        'external_data_share',
        'noc_validity',
        'valid_for_upload',
        'nsw_license_id',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
