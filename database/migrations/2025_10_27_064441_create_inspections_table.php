<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('department_id');
            $table->string('request_id')->nullable();
            $table->string('unit_name')->nullable();
            $table->text('proposed_date')->nullable();
            $table->date('inspection_date')->nullable();
            $table->text('reason_for_request')->nullable();
            $table->integer('inspector')->nullable();
            $table->string('inspection_type')->nullable();
            $table->string('inspection_for')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('pending');
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
