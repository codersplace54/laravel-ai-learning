<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripuraMasterData extends Model
{
    protected $fillable = [
        'id',
        'district_name',
        'district_code',
        'sub_division',
        'sub_lgd_code',
        'ulb_name',
        'ulb_lgd_code',
        'name_of_gp_vc_or_ward',
        'gp_vc_ward_lgd_code',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
