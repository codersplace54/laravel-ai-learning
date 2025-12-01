<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kya_master', function (Blueprint $table) {
            $table->enum('approval_type', ['industry', 'utility'])->default('industry')->after('id');
        });

        DB::table('kya_master')->whereNull('approval_type')->update(['approval_type' => 'industry']);
    }

    public function down(): void
    {
        Schema::table('kya_master', function (Blueprint $table) {
            $table->dropColumn('approval_type');
        });
    }
};
