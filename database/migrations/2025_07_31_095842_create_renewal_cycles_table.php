<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('renewal_cycles', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('service_id');
            $table->string('renewal_title');
            $table->string('renewal_period');
            $table->string('renewal_period_custom')->nullable();
            $table->string('renewal_target_days')->nullable();
            $table->string('renewal_window_days')->nullable();
            $table->date('fixed_renewal_start_date')->nullable();
            $table->date('fixed_renewal_end_date')->nullable();
            $table->enum('late_fee_applicable', ['yes', 'no']);
            $table->enum('late_fee_calculation_dynamic', ['yes', 'no']);
            $table->string('late_fee_fixed_amount')->nullable();
            $table->string('late_fee_calculated_amount')->nullable();
            $table->enum('allow_renewal_input_form', ['yes', 'no']);
            $table->integer('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_cycles');
    }
};
