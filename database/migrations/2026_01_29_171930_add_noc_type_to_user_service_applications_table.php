<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->enum('NOC_mode', ['online', 'offline'])->after('NOC_certificate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->dropColumn('NOC_mode');
        });
    }
};
