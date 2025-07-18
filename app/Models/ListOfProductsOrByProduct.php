<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListOfProductsOrByProduct extends Model
{
    protected $table = 'list_of_products_or_byproducts';

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

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
