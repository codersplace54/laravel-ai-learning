<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Support\Facades\Auth;
use App\Traits\LogsActivity;

class UserServiceApplication extends Model
{
    use LogsModelActivity, LogsActivity;
    protected $fillable = [
        'id',
        'user_id',
        'service_id',
        'renewal_cycle_id',
        'previous_application_id',
        'renewal',
        'renewalYear',
        'applicationId',   //  applicationId is the application number  auto generated while storing the application
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
        'total_fee',
        'effective_fee',
        'payment_head',
        'payment_url',
        'paid_amount',
        'current_step_number',
        'max_processing_date',
        'created_at',
        'updated_at',

        'external_application_id',
        'external_status',
        'external_payment_status',
        'external_payment_link',
        'external_max_processing_date',
        'external_noc_number',
        'external_valid_till',
        'external_remarks',
        'is_third_party',
        'egras_scheme_code',
        'license_id',
        'NOC_mode',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        // 'NOC_application_date' => 'datetime:Y-m-d H:i:s',
        // 'NOC_generationDate' => 'datetime:Y-m-d H:i:s',
        'PreviousNOCexpiryDate' => 'datetime:Y-m-d H:i:s',
        'application_date' => 'datetime:Y-m-d H:i:s',
    ];

    public function my_feedback()
    {
        return $this->hasOne(UserFeedback::class, 'service_id', 'service_id')
            ->where('user_id', Auth::id());
    }

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

    public function latestWorkflow()
    {
        return $this->hasOne(ApplicationWorkflowAssignment::class, 'application_id')
            ->latestOfMany();
    }

    public function unit()
    {
        return $this->belongsTo(UnitDetail::class, 'user_id', 'user_id');
    }

    public function renewal_cycle()
    {
        return $this->belongsTo(RenewalCycle::class, 'renewal_cycle_id');
    }

    public function workflowHistory()
    {
        return $this->hasMany(ApplicationWorkflowHistory::class, 'application_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(PaymentOrder::class, 'application_id', 'id');
    }

    public function appeal()
    {
        return $this->hasOne(Appeal::class, 'application_id');
    }

    public function getNocCertificateUrlAttribute()
    {
        if (!$this->NOC_certificate) {
            return null;
        }

        if ($this->is_third_party) {
            return $this->NOC_certificate;
        }

        $path = $this->NOC_certificate;
        if (str_starts_with($path, 'uploads/') || str_starts_with($path, 'sites/')) {
            return config('app.url') . '/storage/' . $path;
        }

        return $path;
    }

    public function serviceWorkflow()
    {
        return $this->hasMany(ServiceApprovalFlow::class, 'service_id', 'service_id');
    }

    public function labourDeposit()
    {
        return $this->hasOne(LabourDeposit::class, 'application_id');
    }
}
