<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->string('external_application_id')->nullable()->after('NSW_Push_Document_ID');
            $table->string('external_status')->nullable()->after('external_application_id');
            $table->enum('external_payment_status', ['pending', 'paid', 'failed'])->nullable()->after('external_status');
            $table->date('external_max_processing_date')->nullable()->after('external_payment_status');
            $table->string('external_noc_number')->nullable()->after('external_max_processing_date');
            $table->date('external_valid_till')->nullable()->after('external_noc_number');
            $table->text('external_remarks')->nullable()->after('external_valid_till');
            $table->integer('is_third_party')->default(0)->after('external_remarks');
        });
    }


    public function down(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->dropColumn([
                'external_application_id',
                'external_status',
                'external_payment_status',
                'external_max_processing_date',
                'external_noc_number',
                'external_valid_till',
                'external_remarks',
                'is_third_party',
            ]);
        });
    }
};
