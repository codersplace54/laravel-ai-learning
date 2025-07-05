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
            $table->string('owner_details_name');
            $table->string('owner_details_fathers_name');
            $table->text('owner_details_residential_address');
            $table->string('owner_details_police_station');
            $table->string('owner_details_pin');
            $table->string('owner_aadhar_no');
            $table->string('owner_details_mobile');
            $table->string('owner_details_alternate_mobile')->nullable();
            $table->string('owner_details_aadhar_no');
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
            ]);
            $table->string('owner_details_email');
            $table->date('owner_details_dob');
            $table->enum('owner_details_social_status', [
                'General',
                'SC',
                'ST',
                'OBC'
            ]);
            $table->enum('owner_details_is_differently_abled', [
                'YES',
                 'NO'
            ]);
            $table->enum('owner_details_is_women_entrepreneur', [
                'YES',
                'NO'
            ]);
            $table->enum('owner_details_is_minority', [
                'YES',
                'NO'
            ]);
            $table->string('owner_details_photo');

            $table->string('manager_details_name');
            $table->string('manager_details_fathers_name');
            $table->text('manager_details_residential_address');
            $table->string('manager_details_police_station');
            $table->string('manager_details_pin');
            $table->string('manager_details_mobile');
            $table->string('manager_details_aadhar_no');
            $table->date('manager_details_dob');
            $table->string('manager_details_photo');

            $table->string('signature_authorization_of_owner');
            $table->string('factory_occupiers_signature');
            $table->string('factory_managers_signature');

            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('management_details');
    }
};
