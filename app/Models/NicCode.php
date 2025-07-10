<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NicCode extends Model
{
       protected $fillable = [
        'id',
        'nic_2_digit_code',
        'nic_2_digit_code_description',
        'nic_4_digit_code',
        'nic_4_digit_code_description',
        'nic_5_digit_code',
        'nic_5_digit_code_description',
        'added_by',
        'created_at',
        'updated_at',
    ];
}
