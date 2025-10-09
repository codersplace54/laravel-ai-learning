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
        'default_source_table',
        'default_source_column',
        'data_source',
        'description',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by'

    ];

    public function service()
    {
        return $this->belongsTo(ServiceMaster::class, 'service_id');
    }
}
