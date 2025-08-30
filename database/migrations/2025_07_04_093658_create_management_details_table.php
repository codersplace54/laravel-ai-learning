<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('management_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unique()->nullable();
            $table->string('owner_details_name')->nullable();
            $table->string('owner_details_fathers_name')->nullable();
            $table->text('owner_details_residential_address')->nullable();
            $table->string('owner_details_police_station')->nullable();
            $table->string('owner_details_pin')->nullable();
            $table->string('owner_aadhar_no')->nullable();
            $table->string('owner_details_mobile')->nullable();
            $table->string('owner_details_alternate_mobile')->nullable();
            $table->enum('owner_details_status', [
                'Owner',
                'Managing Director',
                'CEO',
                'Chairman',
                'Partner',
                'COO',
                'CFO',
                'Director',
                'VP',
                'Chief Operating Officer',
                'Chief Financial Officer',
                'Chief Executive Officer',
                'Vice President',
                'President'
            ])->nullable();
            $table->string('owner_details_email')->nullable();
            $table->date('owner_details_dob')->nullable();
            $table->enum('owner_details_social_status', [
                'General',
                'SC',
                'ST',
                'OBC'
            ])->nullable();
            $table->enum('owner_details_is_differently_abled', [
                'YES',
                 'NO'
            ])->nullable();
            $table->enum('owner_details_is_women_entrepreneur', [
                'YES',
                'NO'
            ])->nullable();
            $table->enum('owner_details_is_minority', [
                'YES',
                'NO'
            ])->nullable();
            $table->string('owner_details_photo')->nullable();

            $table->string('manager_details_name')->nullable();
            $table->string('manager_details_fathers_name')->nullable();
            $table->text('manager_details_residential_address')->nullable();
            $table->string('manager_details_police_station')->nullable();
            $table->string('manager_details_pin')->nullable();
            $table->string('manager_details_mobile')->nullable();
            $table->string('manager_details_aadhar_no')->nullable();
            $table->date('manager_details_dob')->nullable();
            $table->string('manager_details_photo')->nullable();

            $table->string('signature_authorization_of_owner')->nullable();
            $table->string('factory_occupiers_signature')->nullable();
            $table->string('factory_managers_signature')->nullable();

            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('management_details');
    }
};
