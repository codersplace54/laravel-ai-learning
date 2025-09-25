<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proforma_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('proforma_id');
            $table->string('question_label');                 
            $table->string('question_type');                 
            $table->enum('is_required', ['yes', 'no']);
            $table->text('options')->nullable();
            $table->text('default_value')->nullable();
            $table->string('default_source_table')->nullable();
            $table->string('default_source_column')->nullable();
            $table->integer('display_order')->nullable();
            $table->string('group_label')->nullable();
            $table->string('display_width')->nullable();
            $table->integer('status')->default(1);
            $table->enum('validation_required', ['yes', 'no']);
            $table->string('upload_rule')->nullable();
            $table->index(['proforma_id', 'display_order'], 'idx_proforma_questions_order');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proforma_questionnaires');
    }
};
