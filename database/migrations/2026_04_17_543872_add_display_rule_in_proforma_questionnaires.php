<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proforma_questionnaires', function (Blueprint $table) {
            $table->string('display_rule')->nullable()->after('upload_rule');
        });
    }

    public function down(): void
    {
        Schema::table('proforma_questionnaires', function (Blueprint $table) {
            $table->dropColumn('display_rule');
        });
    }
};
