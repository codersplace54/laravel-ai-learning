<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('labour_deposits', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('application_id')->unique();
            $table->integer('contract_labour_deposit')->nullable();
            $table->integer('ismw_labour_deposit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_deposits');
    }
};
