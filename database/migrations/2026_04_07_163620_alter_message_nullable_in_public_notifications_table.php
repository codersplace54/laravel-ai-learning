<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('public_notifications', function (Blueprint $table) {
            $table->string('message')->nullable()->change();
        });
    }


    public function down(): void
    {
        Schema::table('public_notifications', function (Blueprint $table) {
            //
        });
    }
};
