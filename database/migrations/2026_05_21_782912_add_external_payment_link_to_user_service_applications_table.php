<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->string('external_payment_link')->after('external_payment_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->dropColumn('external_payment_link');
        });
    }
};
