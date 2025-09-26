<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceThirdPartyParam extends Model
{
    protected $fillable = [
        'id',
        'service_id',
        'param_name',
        'param_type',
        'param_required',
        'default_value',
        'data_source',
        'description',
        'created_at',
        'updated_at',

    ];

    public function service()
    {
        return $this->belongsTo(ServiceMaster::class, 'service_id');
    }
}
