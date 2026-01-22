<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
             $table->string('verification_token')->nullable();
              $table->enum('is_special', ['yes', 'no']);
        });
    }


    public function down(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->dropColumn('verification_token');
            $table->dropColumn('is_special');
        });
    }
};
