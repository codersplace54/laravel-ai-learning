<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('single_window_reports', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['native', 'third_party']);
            $table->unsignedBigInteger('service_id');
            $table->integer('time_limit')->nullable();
            $table->integer('total_received')->default(0);
            $table->integer('total_processed')->default(0);
            $table->integer('total_approved')->default(0);
            $table->decimal('max_time_to_approve', 10, 2)->default(0);
            $table->decimal('min_time_to_approve', 10, 2)->default(0);
            $table->decimal('avg_time_to_approve', 10, 2)->default(0);
            $table->decimal('median_time_to_approve', 10, 2)->default(0);
            $table->decimal('avg_fee', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['type', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('single_window_reports');
    }
};
