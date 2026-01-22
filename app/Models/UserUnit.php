<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserUnit extends Model
{
    protected $table = 'user_units';

    protected $fillable = [
        'user_id',
        'unit_name',
        'district_id',
        'subdivision_id',
        'ulb_id',
        'ward_id',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function district()
    {
        return $this->belongsTo(TripuraMasterData::class, 'district_id', 'district_code');
    }

    public function subdivision()
    {
        return $this->belongsTo(TripuraMasterData::class, 'subdivision_id', 'sub_lgd_code');
    }

    public function ulb()
    {
        return $this->belongsTo(TripuraMasterData::class, 'ulb_id', 'ulb_lgd_code');
    }

    public function ward()
    {
        return $this->belongsTo(TripuraMasterData::class, 'ward_id', 'gp_vc_ward_lgd_code');
    }
}
