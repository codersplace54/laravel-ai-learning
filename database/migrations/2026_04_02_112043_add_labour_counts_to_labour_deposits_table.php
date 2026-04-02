<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('labour_deposits', function (Blueprint $table) {
            $table->integer('no_of_contract_labour')->nullable()->after('ismw_labour_fee');
            $table->integer('no_of_ismw_labour')->nullable()->after('no_of_contract_labour');
        });
    }


    public function down(): void
    {
        Schema::table('labour_deposits', function (Blueprint $table) {
            //
        });
    }
};
