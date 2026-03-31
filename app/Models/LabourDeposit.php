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
        'created_at',
        'updated_at',

    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
