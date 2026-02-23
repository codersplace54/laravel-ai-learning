<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'document_id',
        'document_type',
        'document_name',
        'issuer',
        'issued_date',
        'document_data',
        'local_path',
        'content_type',
        'downloaded_at'
    ];

    protected $casts = [
        'document_data' => 'array',
        'issued_date' => 'date',
        'downloaded_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}