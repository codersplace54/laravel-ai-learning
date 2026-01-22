<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('GRN_number')->nullable()->after('gateway');
            $table->dateTime('payment_datetime')->nullable()->after('GRN_number');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn('GRN_number');
            $table->dropColumn('payment_datetime');
        });
    }
};
