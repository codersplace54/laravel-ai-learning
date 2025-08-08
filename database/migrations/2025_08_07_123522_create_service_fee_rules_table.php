<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('service_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('service_id')->nullable();
            $table->bigInteger('renewal_cycle_id')->nullable();
            $table->enum('fee_type', ['hardcoded', 'calculated', 'estimated'])->nullable();
            $table->string('fixed_fee')->nullable();
            $table->bigInteger('question_id')->nullable();
            $table->enum('condition_operator', ['=', '!=', '<', '<=', '>', '>=', 'between'])->nullable();
            $table->string('condition_value_start')->nullable();
            $table->string('condition_value_end')->nullable();
            $table->string('fixed_calculated_fee')->nullable();
            $table->string('per_unit_fee')->nullable();
            $table->integer('priority')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_fee_rules');
    }
};
