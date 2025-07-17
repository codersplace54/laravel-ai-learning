<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('raw_materials_to_be_used', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('raw_material_name')->nullable();;
            $table->string('raw_material_quantity_per_month')->nullable();;
            $table->enum('raw_material_unit', [
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
        Schema::dropIfExists('raw_materials_to_be_used');
    }
};
