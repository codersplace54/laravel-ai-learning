<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('existing_licenses', function (Blueprint $table) {
            $table->string('license_file')->after('application_no')->nullable();
            $table->dropColumn('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('existing_licenses', function (Blueprint $table) {
            $table->dropColumn('license_file');
            $table->bigInteger('department_id')->nullable();
        });
    }
};
