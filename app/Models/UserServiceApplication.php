<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserServiceApplication extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'service_id',
        'renewal_cycle_id',
        'renewal',
        'renewalYear',
        'applicationId',
        'application_date',
        'status',
        'application_data',
        'applied_fee',
        'approved_fee',
        'payment_status',
        'remarks',
        'NOC_application_date',
        'NOC_expiry_date',
        'PreviousNOCexpiryDate',
        'payment_transId',
        'GRN_number',
        'payment_time',
        'extra_payment',
        'comments',
        'NOC_certificate',
        'NOC_rejection_certificate',
        'NOC_generationDate',
        'NOC_penalty_amount',
        'NOC_letter_number',
        'NOC_letter_date',
        'NSW_Application_Save_ID',
        'NSW_license_status',
        'NSW_Push_Document_ID',
        'final_fee',
        'current_step_number',
        'max_processing_date',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function service()
    {
        return $this->belongsTo(ServiceMaster::class, 'service_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function workflow()
    {
        return $this->hasMany(ApplicationWorkflowAssignment::class, 'application_id', 'id')
            ->orderBy('step_number');
    }
}
