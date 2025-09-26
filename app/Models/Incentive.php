<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incentive extends Model
{
    protected $fillable = [
        'scheme_id',
        'eligibility_service_id',
        'code',
        'title',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function scheme()
    {
        return $this->belongsTo(Scheme::class);
    }
    public function proformas()
    {
        return $this->hasMany(Proforma::class);
    }
}
