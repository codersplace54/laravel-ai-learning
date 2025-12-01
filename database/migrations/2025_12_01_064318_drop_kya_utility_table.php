<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('kya_utility');
    }

    public function down(): void
    {
        Schema::create('kya_utility', function ($table) {
            $table->id();
            $table->text('question');
            $table->timestamps();
        });
    }
};
