<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_cycles', function (Blueprint $table) {
            $table->enum('late_fee_start_type', ['fixed_date', 'date_of_expiry'])->nullable()->after('late_fee_applicable');
            $table->date('late_fee_start_date')->nullable()->after('late_fee_start_type');
        });
    }

    public function down(): void
    {
        Schema::table('renewal_cycles', function (Blueprint $table) {
            $table->dropColumn('late_fee_start_type');  
            $table->dropColumn('late_fee_start_date');
        });
    }
};
