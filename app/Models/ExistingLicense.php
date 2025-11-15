<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExistingLicense extends Model
{
    protected $fillable = [
        'user_id',
        'service_id',
        'department_id',
        'licensee_name',
        'application_no',
        'valid_from',
        'expiry_date',
        'license_no',
        'action_taken_by',
        'status',
        'created_by',
        'updated_by',
        'license_file',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'expiry_date'=> 'date',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user(){ 
        return $this->belongsTo(User::class); 
    }

    public function service(){ 
        return $this->belongsTo(ServiceMaster::class, 'service_id'); 
    }

    public function department(){ 
        return $this->belongsTo(Department::class, 'department_id'); 
    }

    public function actionTaker(){ 
        return $this->belongsTo(User::class, 'action_taken_by'); 
    }

}
