<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {

        Schema::create('enterprise_details', function (Blueprint $table) {

            $table->id();
            $table->bigInteger('user_id');

            $table->string('constitution_of_enterprise')->nullable();
            $table->string('enterprise_name')->nullable();
            $table->string('business_pan_no', 20)->nullable();
            $table->string('enterprise_address')->nullable();
            $table->string('enterprises_registered_address')->nullable();
            $table->string('habitation_area_building')->nullable();
            $table->bigInteger('pin')->nullable();
            $table->string('post_office')->nullable();
            $table->string('police_station')->nullable();

            $table->string('authorized_representative_name')->nullable();
            $table->string('authorized_representative_designation')->nullable();
            $table->string('authorized_representative_aadhar_no', 20)->nullable();
            $table->string('authorized_representative_mobile_no', 20)->nullable();
            $table->string('authorized_representative_email_id')->nullable();
            $table->string('authorized_representative_alternate_mobile_no', 20)->nullable();
            $table->string('authorized_representative_phone_no', 20)->nullable();

            $table->enum('proposal_for', ['New Unit', 'Existing Unit'])->nullable();
            $table->string('proposed_date_of_commissioning')->nullable();


            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('enterprise_details');
    }
};
