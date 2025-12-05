<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentOrder extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'application_id',
        'payment_amount',
        'payment_status',
        'gateway',
        'gateway_order_id',
        'transaction_id',
        'gateway_response',
        'created_at',
        'updated_at',
        'GRN_number',
        'payment_datetime',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function application()
    {
        return $this->belongsTo(UserServiceApplication::class, 'application_id', 'id');
    }
}
