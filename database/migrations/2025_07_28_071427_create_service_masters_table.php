<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('service_masters', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('department_id');
            $table->string('service_title_or_description');
            $table->string('noc_name');
            $table->string('noc_short_name');
            $table->enum('noc_type', ['CFE', 'CFO', 'Renewal', 'Special', 'Others']);
            $table->enum('noc_payment_type', ['Estimated', 'Hardcoded', 'Calculated']);
            $table->integer('target_days')->nullable();
            $table->enum('has_input_form', ['yes', 'no']);
            $table->string('depends_on_services')->nullable();
            $table->enum('generate_id', ['yes', 'no']);
            $table->enum('generate_pdf', ['yes', 'no']);
            $table->string('generated_id_format')->nullable();
            $table->string('label_noc_date')->nullable();
            $table->string('label_noc_doc')->nullable();
            $table->string('label_noc_no')->nullable();
            $table->string('label_valid_till')->nullable();
            // $table->enum('show_letter_date', ['yes', 'no']);
            // $table->enum('show_letter_no', ['yes', 'no']);
            $table->enum('show_valid_till', ['yes', 'no']);
            $table->enum('auto_renewal', ['yes', 'no']);
            $table->enum('external_data_share', ['yes', 'no']);
            $table->integer('noc_validity')->nullable();
            $table->enum('valid_for_upload', ['yes', 'no']);
            $table->string('nsw_license_id')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_masters');
    }
};
