<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('list_of_products_or_byproducts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('product_name');
            $table->string('product_production_capacity_per_month');
            $table->string('product_average_production_per_month');
            $table->enum('unit', [
                'Liters Numbers Per Month',
                'Kilo Liters Number Per Month',
                'Meter Numbers Per Month',
                'Square Meter Numbers Per Month',
                'Cubic Meter Numbers Per Month',
                'Foot Numbers Per Month',
                'Square Foot Numbers Per Month',
                'Tonnes Numbers Per Month',
                'Metric Tonnes Numbers Per Month',
                'Million Unit (MU)'
            ]);
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('list_of_products_or_byproducts');
    }
};
