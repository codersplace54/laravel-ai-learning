<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentUser extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'department_id',
        'designation',
        'block_id',
        'subdivision_id',
        'district_id',
        'hierarchy_level',
        'is_active',
        'inspector',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
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
        return $this->belongsTo(TripuraMasterData::class, 'block_id', 'ulb_lgd_code');
    }
}
