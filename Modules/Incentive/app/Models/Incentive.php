<?php

namespace Modules\Incentive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Incentive\Database\Factories\IncentiveFactory;

class Incentive extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): IncentiveFactory
    // {
    //     // return IncentiveFactory::new();
    // }
}
