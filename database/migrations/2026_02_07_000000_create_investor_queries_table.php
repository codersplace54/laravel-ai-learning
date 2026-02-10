<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        
        Schema::create('investor_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query_topic');
            $table->string('company_name');
            $table->text('company_address');
            $table->string('city');
            $table->string('state');
            $table->text('present_activities')->nullable();
            $table->enum('area_of_interest', ['manufacturing', 'services', 'trading'])->nullable();
            $table->bigInteger('department_id')->nullable();
            $table->string('full_name');
            $table->string('email');
            $table->string('mobile');
            $table->string('query_summary');
            $table->text('query_details');
            $table->string('attachment')->nullable();
            $table->string('reference_id')->nullable();
            $table->enum('status', ['pending', 'resolved', 'closed'])->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_queries');
    }
};
