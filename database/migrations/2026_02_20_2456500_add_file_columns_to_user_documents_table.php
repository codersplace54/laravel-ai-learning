<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->string('local_path')->nullable()->after('document_data');
            $table->string('content_type')->nullable()->after('local_path');
            $table->timestamp('downloaded_at')->nullable()->after('content_type');
        });
    }

    public function down()
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->dropColumn(['local_path', 'content_type', 'downloaded_at']);
        });
    }
};
