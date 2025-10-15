<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_questionnaires', function (Blueprint $table) {
            $table->string('upload_rule')->nullable()->after('section_name');
        });
    }

    public function down(): void
    {
        Schema::table('service_questionnaires', function (Blueprint $table) {
            $table->dropColumn('upload_rule');
        });
    }
};
