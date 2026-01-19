<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->boolean('updated_by_cron')->default(false)->after('gateway_response');
        });
    }

    public function down()
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn('updated_by_cron');
        });
    }
};