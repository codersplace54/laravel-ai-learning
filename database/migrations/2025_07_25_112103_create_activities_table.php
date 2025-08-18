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
            $table->bigInteger('user_id')->nullable();
            $table->string('activity_of_enterprise')->nullable();
            $table->string('nic_2_digit_code')->nullable();
            $table->string('nic_4_digit_code')->nullable();
            $table->string('nic_5_digit_code')->nullable();
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
