<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('establishment_fee_paid')->nullable()->after('payment_amount');
            $table->string('operational_fee_paid')->nullable()->after('establishment_fee_paid');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn(['establishment_fee_paid', 'operational_fee_paid']);
        });
    }
};
