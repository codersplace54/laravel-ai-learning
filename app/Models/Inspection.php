<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\LogsModelActivity;

class Inspection extends Model
{
    use LogsModelActivity;
    protected $fillable = [
        'id',
        'department_id',
        'user_id',
        'request_id',
        'unit_name',
        'proposed_date',
        'inspection_date',
        'reason_for_request',
        'inspector',
        'inspection_type',
        'inspection_for',
        'department_type',
        'remarks',
        'status',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function inspectorUser()
    {
        return $this->belongsTo(User::class, 'inspector', 'id');
    }

    public function unit()
    {
        return $this->belongsTo(UnitDetail::class, 'unit_name', 'id');
    }
}
