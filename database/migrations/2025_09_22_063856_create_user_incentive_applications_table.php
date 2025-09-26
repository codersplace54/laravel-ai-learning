<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_incentive_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no')->nullable();
            $table->bigInteger('user_id');
            $table->bigInteger('scheme_id');
            $table->bigInteger('proforma_id');
            $table->enum('application_type', ['eligibility','claim']);
            $table->enum('workflow_status', ['draft','submitted','under_review_da','under_review_gm','approved','rejected','sent_back'])->default('draft');
            $table->bigInteger('current_reviewer_user_id')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('decided_at')->nullable();

            $table->string('eligibility_certificate_no')->nullable();
            $table->string('eligibility_certificate_path')->nullable();

            $table->bigInteger('eligibility_application_id')->nullable();
            $table->enum('claim_type', ['one_time','monthly','quarterly'])->nullable();
            $table->date('claim_period_start')->nullable(); 
            $table->date('claim_period_end')->nullable(); 
            $table->string('claim_calculated')->nullable();  

            $table->json('form_answers_json')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_incentive_applications');
    }
};
