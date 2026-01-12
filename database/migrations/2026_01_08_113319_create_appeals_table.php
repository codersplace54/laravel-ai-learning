<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('application_id');
            $table->bigInteger('user_id');
            $table->bigInteger('department_id');
            $table->string('appeal_file')->nullable();
            $table->text('remarks_from_user')->nullable();
            $table->string('status')->default('pending');
            $table->text('remarks_by_dept')->nullable();
            $table->string('dept_file')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }
};
