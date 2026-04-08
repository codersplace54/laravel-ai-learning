<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'user_service_applications',
            'application_workflow_assignments',
            'application_workflow_history',
            'incentive_workflow_histories',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->text('remarks')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'user_service_applications',
            'application_workflow_assignments',
            'application_workflow_history',
            'user_incentive_applications',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('remarks')->nullable()->change();
            });
        }
    }
};
