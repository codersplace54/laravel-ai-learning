<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('enterprise_details', function (Blueprint $table) {
            $table->string('authorized_representative_gstNumber')->nullable()->after('authorized_representative_phone_no');

            $table->string('authorized_representative_cin_number')->nullable()->after('authorized_representative_gstNumber');
        });
    }


    public function down(): void
    {
        Schema::table('enterprise_details', function (Blueprint $table) {
            $table->dropColumn([
                'authorized_representative_gstNumber',
                'authorized_representative_cin_number'
            ]);
        });
    }
};
