<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('application_workflow_assignments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('application_id');
            $table->bigInteger('service_id');
            $table->integer('step_number');
            $table->enum('step_type', ['validation', 'review', 'screening', 'scrutiny', 'approval']);
            $table->bigInteger('department_id');
            $table->enum('hierarchy_level', ['block', 'subdivision', 'district', 'state1', 'state2', 'state3'])->nullable();
            $table->boolean('assigned_to_group')->default(true);
            $table->enum('status', ['re_submitted','pending', 'in_progress', 'approved', 'rejected','send_back','extra_payment'])->default('pending');
            $table->bigInteger('action_taken_by')->nullable();
            $table->dateTime('action_taken_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('application_workflow_assignments');
    }
};
