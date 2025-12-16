<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationPlan extends Model
{
    protected $fillable = [
        'service_name',
        'unavailability_from',
        'availability_from',
        'hyperlink',
    ];

    protected $casts = [
        'unavailability_from' => 'date',
        'availability_from'   => 'date',
    ];
}
