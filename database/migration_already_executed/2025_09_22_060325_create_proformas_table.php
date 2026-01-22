<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proformas', function (Blueprint $table) {
            $table->id();
             $table->bigInteger('scheme_id');
            $table->string('code'); 
            $table->string('title');
            $table->string('depends_on_proforma_ids')->nullable();
            $table->enum('proforma_type', ['eligibility','claim']);
            $table->enum('claim_type', ['one_time','monthly','quarterly','half_yearly','annually'])->nullable(); 
            $table->text('description')->nullable();
            $table->integer('display_order')->nullable();
            $table->integer('status')->default(1);
            $table->unique(['scheme_id','code'], 'uq_proformas_scheme_code');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proformas');
    }
};
