<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalFeeRule extends Model
{
    protected $fillable = [
        'id',
        'service_id',
        'renewal_cycle_id',
        'fee_type',
        'fixed_fee',
        'question_id',
        'condition_operator',
        'condition_value_start',
        'condition_value_end',
        'calculated_fee',
        'fixed_calculated_fee',
        'per_unit_fee',
        'priority',
        'status',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'

    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function renewalCycles()
    {
        return $this->belongsTo(RenewalCycle::class, 'service_id');
    }

    public function questions()
    {
        return $this->belongsTo(ServiceQuestionnaire::class, 'service_id');
    }
}
