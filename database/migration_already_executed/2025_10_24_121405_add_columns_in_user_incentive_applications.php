<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->text('subsidy_report')->nullable()->after('form_answers_json');
        });
    }

    public function down(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->dropColumn('subsidy_report');
        });
    }
};
