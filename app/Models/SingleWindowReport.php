<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ServiceMaster;

class SingleWindowReport extends Model
{
    public function service()
    {
        return $this->belongsTo(ServiceMaster::class, 'service_id');
    }

    protected $fillable = [
        'type',
        'service_id',
        'total_received',
        'total_processed',
        'total_approved',
        'max_time_to_approve',
        'min_time_to_approve',
        'avg_time_to_approve',
        'median_time_to_approve',
        'avg_fee',
    ];
}
