<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_feedbacks', function (Blueprint $table) {
            $table->enum('status', ['pending', 'resolved'])
                  ->default('pending')
                  ->after('remark');
            $table->bigInteger('resolved_by')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('user_feedbacks', function (Blueprint $table) {
            $table->dropColumn(['status', 'resolved_by']);
        });
    }
};
