<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->enum('is_inspection_dept', ['yes', 'no'])->default('no')->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('is_inspection_dept');
        });
    }
};
