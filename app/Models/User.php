<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Models\Concerns\LogsModelActivity;

class User extends Authenticatable implements JWTSubject
{

    use HasFactory, Notifiable, LogsModelActivity;

    protected $fillable = [
        'id',
        'name_of_enterprise',
        'authorized_person_name',
        'email_id',
        'pan',
        'mobile_no',
        'user_name',
        'bin',
        'district_id',
        'subdivision_id',
        'ulb_id',
        'ward_id',
        'registered_enterprise_address',
        'registered_enterprise_city',
        'user_type',
        'password',
        'status',
        'current_token',
        'created_at',
        'updated_at',
        'is_mobile_verified',
        'business_activity',
        'old_id',
        'whatsapp_no',
        'password_reset_required',
        'is_pan_verified',
        'dob'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function enterprise_details()
    {
        return $this->hasOne(EnterpriseDetail::class, 'user_id');
    }

    public function management_details()
    {
        return $this->hasOne(ManagementDetails::class, 'user_id');
    }

    public function department_user()
    {
        return $this->hasOne(DepartmentUser::class, 'user_id');
    }

    public function district()
    {
        return $this->belongsTo(TripuraMasterData::class, 'district_id', 'district_code');
    }

    public function subdivision()
    {
        return $this->belongsTo(TripuraMasterData::class, 'subdivision_id', 'sub_lgd_code');
    }

    public function ulb()
    {
        return $this->belongsTo(TripuraMasterData::class, 'ulb_id', 'ulb_lgd_code');
    }

    public function ward()
    {
        return $this->belongsTo(TripuraMasterData::class, 'ward_id', 'gp_vc_ward_lgd_code');
    }

    public function applications()
    {
        return $this->hasMany(UserServiceApplication::class, 'user_id', 'id');
    }

    public function department_user_location()
    {
        return $this->hasMany(DepartmentUser::class, 'user_id');
    }

    public function whatsappLogs()
    {
        return $this->hasMany(WhatsappLog::class, 'user_id');
    }
}
