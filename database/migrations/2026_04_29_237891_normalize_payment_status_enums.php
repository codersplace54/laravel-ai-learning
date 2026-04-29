<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize payment_orders: initiated->pending, success->paid, fail->failed
        DB::table('payment_orders')
            ->whereIn('payment_status', ['initiated', 'success', 'fail'])
            ->update([
                'payment_status' => DB::raw("
                    CASE payment_status
                        WHEN 'initiated' THEN 'pending'
                        WHEN 'success'   THEN 'paid'
                        WHEN 'fail'      THEN 'failed'
                        ELSE payment_status
                    END
                ")
            ]);

        // Normalize user_service_applications: initiated->pending, success->paid
        DB::table('user_service_applications')
            ->whereIn('payment_status', ['initiated', 'success'])
            ->update([
                'payment_status' => DB::raw("
                    CASE payment_status
                        WHEN 'initiated' THEN 'pending'
                        WHEN 'success'   THEN 'paid'
                        ELSE payment_status
                    END
                ")
            ]);

        // Change columns to ENUM 
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->enum('payment_status', ['pending', 'paid', 'failed'])
                  ->default('pending')
                  ->change();
        });

        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->enum('payment_status', ['pending', 'paid', 'failed'])
                  ->default('pending')
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('payment_status')->default('pending')->change();
        });

        Schema::table('user_service_applications', function (Blueprint $table) {
            $table->string('payment_status')->default('pending')->change();
        });
    }
};
