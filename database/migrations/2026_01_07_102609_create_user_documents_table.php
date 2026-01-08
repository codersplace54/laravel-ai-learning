<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_documents', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('document_id')->unique();
            $table->enum('document_type', ['issued', 'uploaded']);
            $table->string('document_name');
            $table->string('issuer')->nullable();
            $table->date('issued_date')->nullable();
            $table->json('document_data');
            $table->timestamps();
            
            $table->index(['user_id', 'document_type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_documents');
    }
};