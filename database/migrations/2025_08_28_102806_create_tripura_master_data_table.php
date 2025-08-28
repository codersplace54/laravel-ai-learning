<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tripura_master_data', function (Blueprint $table) {
            $table->id();
            $table->string('district_name')->nullable();
            $table->string('district_code')->nullable();
            $table->string('sub_division')->nullable();
            $table->string('sub_lgd_code')->nullable();
            $table->string('ulb_name')->nullable();
            $table->string('ulb_lgd_code')->nullable();
            $table->string('name_of_gp_vc_or_ward')->nullable();
            $table->string('gp_vc_ward_lgd_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tripura_master_data');
    }
};
