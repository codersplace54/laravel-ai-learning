<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirdPartyStatusLog extends Model
{
    protected $fillable = [
        'id',
        'service_id',
        'application_id',
        'swaagat_user_id',
        'service_status',
        'mobile_no',
        'application_date',
        'updation_date',
        'action_by',
        'remark',
        'payment_amount',
        'payment_status',
        'payment_url',
        'egras_account_head',
        'noc_url',
        'noc_file',
        'created_at',
        'updated_at',
    ];
}
