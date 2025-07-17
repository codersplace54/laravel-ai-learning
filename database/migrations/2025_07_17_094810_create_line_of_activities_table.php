<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{


    public function up(): void
    {
        Schema::create('line_of_activities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->enum('thrust_sector', [
                'Agri & Horticultural Produce',
                'Bamboo',
                'Gas',
                'Hospital/Nursing Home',
                'Hotel',
                'Rubber',
                'Tea',
                'Tourism Promoting Activites(Water-Sports, Ropeways, Adventure and Leisure Sports)'
            ]);
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('line_of_activities');
    }
};
