<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->enum('service_mode', ['native', 'third_party'])->default('native')->after('status');
            $table->string('third_party_portal_name')->nullable()->after('service_mode');
            $table->string('third_party_redirect_url')->nullable()->after('third_party_portal_name');
            $table->string('third_party_return_url')->nullable()->after('third_party_redirect_url');
            $table->string('third_party_status_api_url')->nullable()->after('third_party_return_url');
            $table->enum('third_party_payment_mode', ['unified', 'external'])->default('unified')->after('third_party_status_api_url');
            $table->integer('is_active')->default(1)->after('third_party_payment_mode');
        });
    }


    public function down(): void
    {
        Schema::table('service_masters', function (Blueprint $table) {
            $table->dropColumn([
                'service_mode',
                'third_party_portal_name',
                'third_party_redirect_url',
                'third_party_return_url',
                'third_party_status_api_url',
                'third_party_payment_mode',
                'is_active'
            ]);
        });
    }
};
