<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('clearances', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('application_id');
            $table->bigInteger('service_id');
            $table->bigInteger('department_id');
            $table->string('licence_number');
            $table->date('licence_date')->nullable();
            $table->date('licence_valid_till')->nullable();
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearances');
    }
};
