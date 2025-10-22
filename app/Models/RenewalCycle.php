<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalCycle extends Model
{
    protected $fillable = [
        'id',
        'service_id',
        'renewal_title',
        'renewal_period',
        'renewal_period_custom',
        'renewal_target_days',
        'renewal_window_days',
        'fixed_renewal_start_date',
        'fixed_renewal_end_date',
        'late_fee_applicable',
        'late_fee_calculation_dynamic',
        'late_fee_fixed_amount',
        'late_fee_calculated_amount',
        'allow_renewal_input_form',
        'is_active',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'late_fee_start_type',
        'late_fee_start_date',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function feerule()
    {
        return $this->hasMany(ServiceFeeRule::class, 'renewal_cycle_id', 'id');
    }
}
