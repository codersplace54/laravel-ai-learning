<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerSharePresidentOrSecretaryDetail extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'name',
        'fathers_name',
        'age',
        'sex',
        'social_status',
        'profession',
        'permanent_address',
        'mobile_no',
        'date_of_birth',
        'date_of_joining',
        'id_proof_doc',
        'signature_image',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];
}
