<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();

            $table->bigInteger('active_application_id')->nullable()->index();
            $table->bigInteger('active_service_id')->nullable()->index();

            $table->string('last_intent')->nullable();
            $table->string('title')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_sessions');
    }
};
