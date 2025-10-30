<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('service_approval_flows', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('service_id');
            $table->integer('step_number');
            $table->enum('step_type', ['validation', 'review', 'screening', 'scrutiny', 'approval']);
            $table->bigInteger('department_id');
            $table->enum('hierarchy_level', ['block', 'subdivision1', 'subdivision2', 'subdivision3', 'district1', 'district2', 'district3', 'state1', 'state2', 'state3']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_approval_flows');
    }
};
