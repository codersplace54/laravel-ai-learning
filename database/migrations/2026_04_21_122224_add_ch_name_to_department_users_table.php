<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('department_users', function (Blueprint $table) {
            $table->string('ch_name')->nullable()->after('district_id');
        });
    }


    public function down(): void
    {
        Schema::table('department_users', function (Blueprint $table) {
            //
        });
    }
};
