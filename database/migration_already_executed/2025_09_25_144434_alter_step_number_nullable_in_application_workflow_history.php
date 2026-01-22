<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('application_workflow_history', function (Blueprint $table) {
            $table->unsignedInteger('step_number')->nullable()->change();
            $table->enum('step_type', ['validation', 'review', 'screening', 'scrutiny', 'approval'])->nullable()->change();
            $table->bigInteger('department_id')->nullable()->change();
            $table->enum('hierarchy_level', ['block', 'subdivision1', 'subdivision2', 'subdivision3', 'district1', 'district2', 'district3', 'state1', 'state2', 'state3'])->nullable()->change();
            $table->bigInteger('action_taken_by')->nullable()->change();
            $table->dateTime('action_taken_at')->nullable()->change();
            $table->enum('status', ['pending', 'in_progress', 'approved', 'rejected', 'send_back', 'extra_payment', 'saved'])->nullable()->change();
        });
    }


    public function down(): void
    {
        Schema::table('application_workflow_history', function (Blueprint $table) {
            $table->unsignedInteger('step_number')->nullable()->change();
            $table->enum('step_type', ['validation', 'review', 'screening', 'scrutiny', 'approval'])->nullable()->change();
            $table->bigInteger('department_id')->nullable()->change();
            $table->enum('hierarchy_level', ['block', 'subdivision1', 'subdivision2', 'subdivision3', 'district1', 'district2', 'district3', 'state1', 'state2', 'state3'])->nullable()->change();
            $table->bigInteger('action_taken_by')->nullable()->change();
            $table->dateTime('action_taken_at')->nullable()->change();
            $table->enum('status', ['pending', 'in_progress', 'approved', 'rejected', 'send_back', 'extra_payment', 'saved'])->nullable()->change();
        });
    }
};
