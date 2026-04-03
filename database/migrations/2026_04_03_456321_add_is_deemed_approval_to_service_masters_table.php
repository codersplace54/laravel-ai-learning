<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->tinyInteger('is_deemed_approval')->default(0)->after('target_days');
        });
    }

    public function down(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->dropColumn('is_deemed_approval');
        });
    }
};
