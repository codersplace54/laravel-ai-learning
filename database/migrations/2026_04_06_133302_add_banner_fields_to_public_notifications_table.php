<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('public_notifications', function (Blueprint $table) {
            $table->enum('is_banner', ['yes', 'no'])->default('no')->after('featured');
        });
    }


    public function down(): void
    {
        Schema::table('public_notifications', function (Blueprint $table) {
            //
        });
    }
};
