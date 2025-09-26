<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('application_workflow_history', function (Blueprint $table) {
            $table->string('external_status')->nullable()->after('remarks');
            $table->integer('external_payment_amount')->nullable()->after('external_status');
            $table->enum('external_payment_status', ['pending', 'paid', 'failed'])->nullable()->after('external_payment_amount');
            $table->string('external_noc_url')->nullable()->after('external_payment_status');
            $table->string('external_noc_file')->nullable()->after('external_noc_url');
            $table->enum('source', ['native', 'third_party'])->default('native')->after('external_noc_file');
        });
    }

    public function down(): void
    {
        Schema::table('application_workflow_history', function (Blueprint $table) {
            $table->dropColumn([
                'external_status',
                'external_payment_amount',
                'external_payment_status',
                'external_noc_url',
                'external_noc_file',
                'source',
            ]);
        });
    }
};
