<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->text('application_id')->nullable();
            $table->string('payment_amount')->nullable();
            $table->string('payment_status')->default('initiated');
            $table->string('gateway')->nullable();
            $table->string('gateway_order_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->text('gateway_response')->nullable();
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
