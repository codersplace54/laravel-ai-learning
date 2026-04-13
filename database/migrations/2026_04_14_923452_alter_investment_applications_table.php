<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_applications', function (Blueprint $table) {
            $table->bigInteger('department_id')->nullable()->after('status');
            $table->renameColumn('admin_note', 'remark');
        });
    }

    public function down(): void
    {
        Schema::table('investment_applications', function (Blueprint $table) {
            $table->dropColumn('department_id');
            $table->renameColumn('remark', 'admin_note');
        });
    }
};
