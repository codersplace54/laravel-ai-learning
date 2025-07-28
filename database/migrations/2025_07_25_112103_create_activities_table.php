<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('activity_of_enterprise');
            $table->string('nic_2_digit_code');
            $table->string('nic_4_digit_code');
            $table->string('nic_5_digit_code');
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
