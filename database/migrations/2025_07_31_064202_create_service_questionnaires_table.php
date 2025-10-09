<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('service_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('service_id');
            $table->string('question_label');
            $table->string('question_type');
            $table->enum('is_required', ['yes', 'no']);
            $table->text('options')->nullable();
            $table->string('default_value')->nullable();
            $table->string('default_source_table')->nullable();
            $table->string('default_source_column')->nullable();
            $table->integer('display_order')->nullable();
            $table->string('group_label')->nullable();
            $table->string('display_width')->nullable();
            $table->boolean('status')->default(1);
            $table->enum('validation_required', ['yes', 'no']);
            $table->string('validation_rule')->nullable();
            $table->string('sample_format')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_questionnaires');
    }
};
