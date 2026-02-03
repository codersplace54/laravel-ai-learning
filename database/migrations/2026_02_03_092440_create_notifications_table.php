<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->integer('display_order')->nullable();
            $table->string('message');
            $table->string('attachment')->nullable();
            $table->date('upload_date')->nullable();
            $table->date('valid_till')->nullable();
            $table->string('status')->default('active');
            $table->enum('featured', ['yes', 'no']);
            $table->string('link')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
