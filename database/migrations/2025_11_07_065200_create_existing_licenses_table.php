<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existing_licenses', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('service_id')->nullable();
            $table->bigInteger('department_id')->nullable();
            $table->string('licensee_name');
            $table->string('application_no')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('license_no')->unique();
            $table->bigInteger('action_taken_by')->nullable();
            $table->enum('status', ['pending','approved','rejected'])->default('pending');
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('existing_licenses');
    }
};
