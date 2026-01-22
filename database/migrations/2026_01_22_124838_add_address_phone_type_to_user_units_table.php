<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('user_units', function (Blueprint $table) {
            $table->string('address')->nullable()->after('unit_name');
            $table->string('phone')->nullable()->after('address');
            $table->enum('type', ['rural', 'urban'])->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('user_units', function (Blueprint $table) {
            $table->dropColumn(['address', 'phone', 'type']);
        });
    }
};
