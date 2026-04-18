<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_applications', function (Blueprint $table) {
            $table->string('dpr_or_other_documents')->nullable()->after('remark');
        });
    }

    public function down(): void
    {
        Schema::table('investment_applications', function (Blueprint $table) {
            $table->dropColumn('dpr_or_other_documents');
        });
    }
};
