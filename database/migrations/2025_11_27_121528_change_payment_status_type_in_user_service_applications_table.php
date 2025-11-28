<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->string('payment_status')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->enum('payment_status', ['pending', 'paid', 'failed'])
                ->nullable()
                ->change();
        });
    }
};
