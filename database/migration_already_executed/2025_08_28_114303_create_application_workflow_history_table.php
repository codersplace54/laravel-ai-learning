<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('application_workflow_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('application_id');
            $table->bigInteger('service_id');
            $table->unsignedInteger('step_number');
            $table->enum('step_type', ['validation', 'review', 'screening', 'scrutiny', 'approval']);
            $table->bigInteger('department_id');
            $table->enum('hierarchy_level', ['block', 'subdivision1', 'subdivision2', 'subdivision3', 'district1', 'district2', 'district3', 'state1', 'state2', 'state3']);
            $table->bigInteger('action_taken_by');
            $table->dateTime('action_taken_at');
            $table->enum('status', ['pending', 'in_progress', 'approved', 'rejected','send_back','extra_payment','saved']);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_workflow_history');
    }
};
