<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `proformas`
        MODIFY COLUMN `claim_type` ENUM(
            'one_time',
            'monthly',
            'quarterly',
            'half_yearly',
            'annually',
            'biennially',
            'triennially',
            'quinquenially'
        ) NULL DEFAULT NULL");
    }

    public function down(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            //
        });
    }
};