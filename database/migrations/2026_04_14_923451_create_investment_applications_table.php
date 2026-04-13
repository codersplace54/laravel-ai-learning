<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_applications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('aadhaar_or_business_id')->nullable();
            $table->text('registered_office_address')->nullable();
            $table->text('communication_address')->nullable();
            $table->string('sector')->nullable();
            $table->string('legal_structure')->nullable();
            $table->string('registration_no')->nullable();
            $table->year('year_of_establishment')->nullable();
            $table->string('gstin')->nullable();
            $table->string('industry_category')->nullable();
            $table->text('brief_proposal')->nullable();
            $table->string('project_title')->nullable();
            $table->string('sub_sector')->nullable();
            $table->decimal('investment_value', 15, 2)->nullable();
            $table->string('employment_to_be_generated')->nullable();
            $table->string('nature_of_activity')->nullable();
            $table->string('proposed_land_type')->nullable();
            $table->decimal('area_required', 10, 2)->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->string('location_address')->nullable();
            $table->string('connectivity_needs')->nullable();
            $table->text('other_requirements')->nullable();
            $table->string('query_id')->nullable()->unique();
            $table->enum('status', ['pending', 'under_review', 'approved', 'rejected'])->default('pending');
            $table->text('admin_note')->nullable();
            $table->string('heard_from')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_applications');
    }
};
