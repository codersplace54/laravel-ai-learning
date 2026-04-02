<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabourDeposit extends Model
{
    protected $fillable = [
        'id',
        'application_id',
        'contract_labour_deposit',
        'ismw_labour_deposit',
        'contract_labour_fee',
        'ismw_labour_fee',
        'no_of_contract_labour',
        'no_of_ismw_labour',
        'grn_number',
        'payment_status',
        'payment_time',
        'scheme_details',
        'created_at',
        'updated_at',

    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
