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
        Schema::create('partner_share_president_or_secretary_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('fathers_name');
            $table->integer('age')->nullable();
            $table->string('sex')->nullable();
            $table->string('social_status')->nullable();
            $table->string('profession')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('mobile_no');
            $table->date('date_of_birth');
            $table->date('date_of_joining')->nullable();
            $table->string('id_proof_doc')->nullable();
            $table->string('signature_image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_share_president_or_secretary_details');
    }
};
