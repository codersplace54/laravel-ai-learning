<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incentive_workflow_histories', function (Blueprint $table) {
            $table->dropColumn('action');
            $table->dropColumn('meta');
        });
    }

    public function down(): void
    {
        Schema::table('incentive_workflow_histories', function (Blueprint $table) {
            $table->string('meta')->nullable();
            $table->string('action');
        });
    }
};
