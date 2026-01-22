<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->dateTime('fixed_expiry_date')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->dropColumn('fixed_expiry_date');
        });
    }
};
