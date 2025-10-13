<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incentive_workflow_histories', function (Blueprint $table) {
            $table->string('from_status')->nullable()->change();
            $table->string('to_status')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('incentive_workflow_histories', function (Blueprint $table) {
                $table->enum('from_status', [
                    'draft',
                    'submitted',
                    'under_review_da',
                    'under_review_gm',
                    'approved',
                    'rejected',
                    'sent_back'
                ])->nullable()->change();

                $table->enum('to_status', [
                    'draft',
                    'submitted',
                    'under_review_da',
                    'under_review_gm',
                    'approved',
                    'rejected',
                    'sent_back'
                ])->nullable()->change();
            });
    }
};
