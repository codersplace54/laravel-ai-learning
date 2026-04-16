<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->string('district_id')->nullable()->after('user_id');
        });

        DB::statement('
            UPDATE user_incentive_applications uia
            JOIN users u ON u.id = uia.user_id
            SET uia.district_id = u.district_id
            WHERE u.district_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->dropColumn('district_id');
        });
    }
};
