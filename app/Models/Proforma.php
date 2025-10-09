<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proforma extends Model
{
    protected $fillable = [
        'scheme_id',
        'incentive_id',
        'code',
        'title',
        'proforma_type',
        'claim_type',
        'max_claim_count',
        'description',
        'display_order',
        'status',
        'depends_on_proforma_ids',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
    
    public function applications()
    {
        return $this->hasMany(UserIncentiveApplication::class);
    }
}
