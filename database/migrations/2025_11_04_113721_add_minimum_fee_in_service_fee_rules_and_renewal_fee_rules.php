<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_fee_rules', function (Blueprint $table) {
            $table->string('minimum_fee')->nullable()->after('per_unit_fee');
        });
        
        Schema::table('renewal_fee_rules', function (Blueprint $table) {
            $table->string('minimum_fee')->nullable()->after('per_unit_fee');
        });
    }

    public function down(): void
    {
        Schema::table('service_fee_rules', function (Blueprint $table) {
            $table->dropColumn('minimum_fee');
        });

        Schema::table('renewal_fee_rules', function (Blueprint $table) {
            $table->dropColumn('minimum_fee');
        });
    }
};
