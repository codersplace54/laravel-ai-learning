<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_plans', function (Blueprint $table) {
            $table->id();
            $table->string('service_name');
            $table->timestamp('unavailability_from')->nullable();
            $table->timestamp('availability_from')->nullable();
            $table->string("hyperlink");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_plans');
    }
};
