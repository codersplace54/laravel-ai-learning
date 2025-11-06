<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incentive_workflow_histories', function (Blueprint $table) {
            $table->string('review_file')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('incentive_workflow_histories', function (Blueprint $table) {
            $table->dropColumn('review_file');
        });
    }
};
