<?php

namespace Modules\CoreApplication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\CoreApplication\Database\Factories\CoreApplicationFactory;

class CoreApplication extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): CoreApplicationFactory
    // {
    //     // return CoreApplicationFactory::new();
    // }
}
