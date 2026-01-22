<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('third_party_status_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('service_id');
            $table->string('application_id');
            $table->bigInteger('swaagat_user_id');
            $table->string('service_status');
            $table->string('mobile_no')->nullable();
            $table->date('application_date')->nullable();
            $table->date('updation_date')->nullable();
            $table->string('action_by')->nullable();
            $table->text('remark')->nullable();
            $table->integer('payment_amount')->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'failed'])->nullable();
            $table->string('payment_url')->nullable();
            $table->string('egras_account_head')->nullable();
            $table->string('noc_url')->nullable();
            $table->binary('noc_file')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
       Schema::dropIfExists('third_party_status_logs');
    }
};
