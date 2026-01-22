<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->index('status', 'idx_usa_status');
            $table->index('service_id', 'idx_usa_service');
            $table->index('user_id', 'idx_usa_user');
        });

        Schema::table('application_workflow_assignments', function (Blueprint $table) {
            $table->index('department_id', 'idx_awa_department');
            $table->index('application_id', 'idx_awa_application');
            $table->index('status', 'idx_awa_status');
            $table->index('hierarchy_level', 'idx_awa_hierarchy');
        });

        Schema::table('service_masters', function (Blueprint $table) {
            $table->index('department_id', 'idx_services_department');
        });
    }

    public function down(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->dropIndex('idx_usa_status');
            $table->dropIndex('idx_usa_service');
            $table->dropIndex('idx_usa_user');
        });

        Schema::table('application_workflow_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_awa_department');
            $table->dropIndex('idx_awa_application');
            $table->dropIndex('idx_awa_status');
            $table->dropIndex('idx_awa_hierarchy');
        });

        Schema::table('service_masters', function (Blueprint $table) {
            $table->dropIndex('idx_services_department');
        });
    }
};
