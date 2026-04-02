<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('labour_deposits', function (Blueprint $table) {
            $table->bigInteger('old_application_id')->nullable()->after('application_id');
            $table->bigInteger('old_user_id')->nullable()->after('old_application_id');
            $table->integer('no_of_contract_labour')->nullable()->after('ismw_labour_fee');
            $table->integer('old_no_of_contract_labour')->nullable()->after('no_of_contract_labour');
            $table->integer('no_of_ismw_labour')->nullable()->after('no_of_contract_labour');
            $table->integer('old_no_of_ismw_labour')->nullable()->after('no_of_ismw_labour');
        });
    }


    public function down(): void
    {
        Schema::table('labour_deposits', function (Blueprint $table) {
            //
        });
    }
};
