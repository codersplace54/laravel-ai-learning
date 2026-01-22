<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->string('service_code')->unique()->nullable()->after('service_title_or_description');
        });
    }

    public function down(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->dropColumn('service_code');
        });
    }
};
