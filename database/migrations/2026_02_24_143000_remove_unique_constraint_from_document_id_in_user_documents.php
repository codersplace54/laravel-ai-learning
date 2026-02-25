<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->dropUnique('user_documents_document_id_unique');
            $table->unique(['user_id', 'document_id']);
        });
    }

    public function down()
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'document_id']);
            $table->unique('document_id');
        });
    }
};
