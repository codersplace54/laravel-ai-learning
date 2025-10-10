<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserIncentiveApplication extends Model
{
    protected $table = 'user_incentive_applications';

    protected $fillable = [
        'user_id',
        'scheme_id',
        'incentive_id',
        'proforma_id',
        'application_type',
        'status',
        'status_changed_on',
        'eligibility_certificate_no',
        'eligibility_certificate_path',
        'linked_eligibility_application_id',
        'claim_type',
        'claim_period_start',
        'claim_period_end',
        'claim_calculated',
        'form_answers_json'
    ];

    protected $casts = [
        'status_changed_on' => 'date',
        'claim_period_start'=> 'date',
        'claim_period_end'  => 'date',
        'submitted_at' => 'datetime',
        'decided_at'   => 'datetime',
        'form_answers_json' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    
    public function proforma(){
        return $this->belongsTo(Proforma::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
}