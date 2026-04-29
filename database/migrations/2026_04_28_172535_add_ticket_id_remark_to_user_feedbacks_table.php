<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_feedbacks', function (Blueprint $table) {
            $table->string('ticket_id')->nullable()->after('id');
            $table->text('remark')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_feedbacks', function (Blueprint $table) {
            $table->dropColumn(['ticket_id', 'remark']);
        });
    }
};
