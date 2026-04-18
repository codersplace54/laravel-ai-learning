<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proforma_questionnaires', function (Blueprint $table) {
            $table->text('display_rule')->nullable()->change();
            $table->text('special_relaxation')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('proforma_questionnaires', function (Blueprint $table) {
            $table->string('display_rule')->nullable()->change();
            $table->string('special_relaxation')->nullable()->change();
        });
    }
};
