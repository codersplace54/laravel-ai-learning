<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schemes', function (Blueprint $table) {
            if (!Schema::hasColumn('schemes', 'created_by')) {
                $table->string('created_by')->nullable();
            }
            if (!Schema::hasColumn('schemes', 'updated_by')) {
                $table->string('updated_by')->nullable();
            }
        });

        Schema::table('proformas', function (Blueprint $table) {
            if (!Schema::hasColumn('proformas', 'created_by')) {
                $table->string('created_by')->nullable();
            }
            if (!Schema::hasColumn('proformas', 'updated_by')) {
                $table->string('updated_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('schemes', function (Blueprint $table) {
            if (Schema::hasColumn('schemes', 'created_by')) {
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('schemes', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
        });

        Schema::table('proformas', function (Blueprint $table) {
            if (Schema::hasColumn('proformas', 'created_by')) {
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('proformas', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
        });
    }
};
