<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('labour_deposits', function (Blueprint $table) {
            $table->integer('contract_labour_fee')->nullable();
            $table->integer('ismw_labour_fee')->nullable();
            $table->string('grn_number')->nullable();
            $table->string('payment_status')->nullable();
            $table->timestamp('payment_time')->nullable();
            $table->json('scheme_details')->nullable();
        });
    }


    public function down(): void
    {
        Schema::table('labour_deposits', function (Blueprint $table) {
            //
        });
    }
};
