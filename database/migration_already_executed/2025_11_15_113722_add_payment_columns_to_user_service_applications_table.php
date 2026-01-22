<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            Schema::table('user_service_applications', function (Blueprint $table) {
                $table->string('effective_fee')->nullable()->after('total_fee');
                $table->string('payment_head')->nullable()->after('effective_fee');
                $table->string('payment_url')->nullable()->after('payment_head');
                $table->string('paid_amount')->nullable()->after('payment_url');
            });
        });
    }


    public function down(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->dropColumn(['effective_fee','payment_head', 'payment_url', 'paid_amount']);
        });
    }
};
