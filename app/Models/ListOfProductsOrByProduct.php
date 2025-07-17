<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListOfProductsOrByProduct extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'product_name',
        'product_production_capacity_per_month',
        'product_average_production_per_month',
        'unit',
        'created_at',
        'updated_at'
    ];
}
