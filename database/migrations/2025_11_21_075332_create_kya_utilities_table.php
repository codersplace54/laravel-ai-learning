<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kya_utility', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kya_utility');
    }
};
