<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->bigInteger('old_id')->nullable()->after('id');
            $table->date('certificate_upload_date')->after('eligibility_certificate_path')->nullable();
            $table->date('application_date')->after('application_type')->nullable();
            $table->date('completion_date')->after('eligibility_application_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->dropColumn(['old_id', 'certificate_upload_date', 'application_date', 'completion_date']);
        });
    }
};
