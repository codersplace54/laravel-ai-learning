<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('user_service_applications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('service_id');
            $table->bigInteger('renewal_cycle_id')->nullable();
            $table->enum('renewal', ['yes', 'no'])->nullable();
            $table->integer('renewalYear')->nullable();
            $table->string('applicationId')->nullable();
            $table->timestamp('application_date')->useCurrent();
            $table->enum('status', ['saved','submitted', 'under_review', 'approved', 'rejected','send_back','re_submitted','extra_payment'])->default('saved');
            $table->string('application_data')->nullable();
            $table->string('applied_fee')->nullable();
            $table->string('approved_fee')->nullable();
            $table->string('payment_status')->nullable();
            $table->text('remarks')->nullable();
            $table->date('NOC_application_date')->nullable();
            $table->date('NOC_expiry_date')->nullable();
            $table->date('PreviousNOCexpiryDate')->nullable();
            $table->string('payment_transId')->nullable();
            $table->string('GRN_number')->nullable();
            $table->timestamp('payment_time')->nullable();
            $table->string('extra_payment')->nullable();
            $table->text('comments')->nullable();
            $table->string('NOC_certificate')->nullable();
            $table->string('NOC_rejection_certificate')->nullable();
            $table->timestamp('NOC_generationDate')->nullable();
            $table->string('NOC_penalty_amount')->nullable();
            $table->string('NOC_letter_number')->nullable();
            $table->date('NOC_letter_date')->nullable();
            $table->string('NSW_Application_Save_ID')->nullable();
            $table->enum('NSW_license_status', ['pending', 'approved', 'rejected', 'expired'])->nullable();
            $table->string('NSW_Push_Document_ID')->nullable();
            $table->string('final_fee')->nullable();
            $table->string('total_fee')->nullable();
            $table->string('current_step_number')->nullable();
            $table->string('max_processing_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_service_applications');
    }
};
