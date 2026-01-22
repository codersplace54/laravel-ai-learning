<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_third_party_params', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('service_id')->nullable();
            $table->string('param_name')->nullable();
            $table->enum('param_type', ['request', 'response'])->nullable();
            $table->integer('param_required')->default(1)->nullable();
            $table->string('default_value')->nullable();
            $table->string('default_source_table')->nullable();
            $table->string('default_source_column')->nullable();
            $table->enum('data_source', ['user_input', 'system_generated', 'static'])->default('user_input')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('service_third_party_params');
    }
};
