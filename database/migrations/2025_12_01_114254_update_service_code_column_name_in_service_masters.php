<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->dropUnique('service_masters_service_code_unique');

            $table->renameColumn('service_code', 'egras_scheme_code');
        });
    }

    public function down(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->renameColumn('egras_scheme_code', 'service_code');
        });
    }
};
