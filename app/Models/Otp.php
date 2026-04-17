<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    const MAX_FAILED_ATTEMPTS = 5;

    protected $fillable = [
        'mobile_no',
        'code',
        'expires_at',
        'is_verified',
        'failed_attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isLocked(): bool
    {
        return $this->failed_attempts >= self::MAX_FAILED_ATTEMPTS;
    }

    public function recordFailedAttempt(): void
    {
        $this->increment('failed_attempts');
    }
}
