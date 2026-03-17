<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\LogsModelActivity;

class UserIncentiveApplication extends Model
{
    use LogsModelActivity;
    protected $table = 'user_incentive_applications';

    protected $fillable = [
        'user_id',
        'scheme_id',
        'proforma_id',
        'application_type',
        'eligibility_certificate_no',
        'eligibility_certificate_path',
        'claim_type',
        'claim_calculated',
        'form_answers_json',
        'subsidy_report',
        'old_id',
        'certificate_upload_date',
        'application_date',
        'completion_date',
        'submitted_at',
        'decided_at',
        'application_no',
        'workflow_status',
        'current_reviewer_user_id',
        'eligibility_application_id',
        'remaining_claim',
    ];

    protected $casts = [
        'status_changed_on' => 'date',
        'claim_period_start'=> 'date',
        'submitted_at' => 'datetime',
        'decided_at'   => 'datetime',
        'form_answers_json' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'certificate_upload_date' => 'date',
        'application_date' => 'date',
    ];
    
    public function proforma(){
        return $this->belongsTo(Proforma::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
}