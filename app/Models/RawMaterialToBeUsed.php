<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawMaterialToBeUsed extends Model
{

    protected $table = 'raw_materials_to_be_used';

    protected $fillable = [
        'id',
        'user_id',
        'raw_material_name',
        'raw_material_quantity_per_month',
        'raw_material_unit',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
