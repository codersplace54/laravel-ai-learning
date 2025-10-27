<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('department_users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('department_id');
            $table->string('designation')->nullable();
            $table->bigInteger('block_id')->nullable();
            $table->bigInteger('subdivision_id')->nullable();
            $table->bigInteger('district_id')->nullable();
            $table->enum('hierarchy_level', ['block', 'subdivision1', 'subdivision2', 'subdivision3', 'district1', 'district2', 'district3', 'state1', 'state2', 'state3']);
            $table->integer('is_active')->default(1);
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('department_users');
    }
};
