<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'users',
            'departments',
            'service_masters',
            'user_service_applications',
        ];

        foreach ($tables as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->bigInteger('old_id')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'users',
            'departments',
            'service_masters',
            'user_service_applications',
        ];

        foreach ($tables as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropColumn('old_id');
            });
        }
    }
};
