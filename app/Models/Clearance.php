<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clearance extends Model
{
    protected $fillable = [
        'user_id',
        'application_id',
        'licence_number',
        'licence_date',
        'service_id',
        'department_id',
        'licence_valid_till',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function service()
    {
        return $this->belongsTo(ServiceMaster::class, 'service_id', 'id');
    }
}
