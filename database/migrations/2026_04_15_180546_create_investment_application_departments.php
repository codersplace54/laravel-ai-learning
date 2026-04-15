<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_applications', function (Blueprint $table) {
            $table->dropColumn('department_id');
            $table->bigInteger('action_taken_by')->nullable()->after('remark');
        });

        Schema::create('investment_application_departments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('investment_application_id');
            $table->bigInteger('department_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('investment_applications', function (Blueprint $table) {
            $table->bigInteger('department_id')->nullable();
            $table->dropColumn('action_taken_by');
        });

        Schema::dropIfExists('investment_application_departments');
    }
};
