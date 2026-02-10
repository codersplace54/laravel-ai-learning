<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentGovernmentOrder extends Model
{
    protected $table = 'department_government_orders';

    protected $fillable = [
        'sl_no',
        'department',
        'government_order_number',
        'government_order_date',
        'subject',
        'url'
    ];
}
