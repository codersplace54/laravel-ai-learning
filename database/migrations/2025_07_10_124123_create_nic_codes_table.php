<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('nic_codes', function (Blueprint $table) {
            $table->id();
            $table->string('nic_2_digit_code');
            $table->string('nic_2_digit_code_description');
            $table->string('nic_4_digit_code');
            $table->string('nic_4_digit_code_description');
            $table->string('nic_5_digit_code')->unique();
            $table->string('nic_5_digit_code_description');
            $table->bigInteger('added_by');
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('nic_codes');
    }
};
