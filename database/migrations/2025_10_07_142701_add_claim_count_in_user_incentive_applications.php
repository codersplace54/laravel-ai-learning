<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->unsignedSmallInteger('remaining_claim')->nullable()->after('claim_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->dropColumn('remaining_claim');
        });
    }
};
