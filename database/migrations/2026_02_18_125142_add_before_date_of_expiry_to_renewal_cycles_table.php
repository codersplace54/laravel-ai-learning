<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('renewal_cycles', function (Blueprint $table) {
            $table->string('before_date_of_expiry')->nullable()->after('allow_renewal_input_form');
        });
    }


    public function down(): void
    {
        Schema::table('renewal_cycles', function (Blueprint $table) {
            $table->dropColumn('before_date_of_expiry');
        });
    }
};
