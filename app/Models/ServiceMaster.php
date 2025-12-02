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
        'allow_repeat_application',
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

        'service_mode',
        'third_party_portal_name',
        'third_party_redirect_url',
        'third_party_return_url',
        'third_party_status_api_url',
        'third_party_payment_mode',
        'is_active',
        'fixed_expiry_date',
        'payment_accountant',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'verification_token',
        'is_special',
        'egras_scheme_code'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function applications()
    {
        return $this->hasMany(UserServiceApplication::class, 'service_id', 'id');
    }

    public function third_party_param()
    {
        return $this->hasMany(ServiceThirdPartyParam::class, 'service_id');
    }

    public function renewalCycles()
    {
        return $this->hasMany(RenewalCycle::class, 'service_id');
    }

    public function questions()
    {
        return $this->hasMany(ServiceQuestionnaire::class, 'service_id');
    }

    public function service_fee_rule()
    {
        return $this->hasMany(ServiceFeeRule::class, 'service_id');
    }

    public function service_approval_flow()
    {
        return $this->hasMany(ServiceApprovalFlow::class, 'service_id');
    }

    public function renewal_fee_rule()
    {
        return $this->hasMany(RenewalFeeRule::class, 'service_id');
    }
}
