<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('ai_chat_session_id')->index();
            $table->bigInteger('user_id')->index();

            $table->string('role'); // user / assistant
            $table->longText('message');

            $table->string('intent')->nullable();
            $table->string('answer_type')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
