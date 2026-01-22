<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('user_units', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('unit_name');
            $table->bigInteger('district_id')->nullable();
            $table->bigInteger('subdivision_id')->nullable();
            $table->bigInteger('ulb_id')->nullable();
            $table->bigInteger('ward_id')->nullable();
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_units');
    }
};
