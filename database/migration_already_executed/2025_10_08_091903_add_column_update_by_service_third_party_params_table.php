<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('service_third_party_params', function (Blueprint $table) {
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_third_party_params', function (Blueprint $table) {
            $table->dropColumn('created_by');
            $table->dropColumn('updated_by');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('created_by');
            $table->dropColumn('updated_by');
        });
    }
};
