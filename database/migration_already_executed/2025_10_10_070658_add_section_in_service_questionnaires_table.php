<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_questionnaires', function (Blueprint $table) {
            $table->enum('is_section', ['yes', 'no'])->nullable()->after('validation_rule');
            $table->string('section_name')->nullable()->after('is_section');
        });
    }

    public function down(): void
    {
        Schema::table('service_questionnaires', function (Blueprint $table) {
            $table->dropColumn('is_section');
            $table->dropColumn('section_name');
        });
    }
};
