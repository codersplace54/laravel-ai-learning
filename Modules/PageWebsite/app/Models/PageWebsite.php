<?php

namespace Modules\PageWebsite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\PageWebsite\Database\Factories\PageWebsiteFactory;

class PageWebsite extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): PageWebsiteFactory
    // {
    //     // return PageWebsiteFactory::new();
    // }
}
