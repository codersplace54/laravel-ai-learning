<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incentive_workflow_histories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('application_id');

            $table->enum('from_status', [
                'draft',
                'submitted',
                'under_review_da',
                'under_review_gm',
                'approved',
                'rejected',
                'sent_back'
            ])->nullable();

            $table->enum('to_status', [
                'draft',
                'submitted',
                'under_review_da',
                'under_review_gm',
                'approved',
                'rejected',
                'sent_back'
            ]);

            $table->string('action');
            $table->bigInteger('action_taken_by')->nullable();

            $table->text('remarks')->nullable();
            $table->string('meta')->nullable();

            $table->dateTime('action_taken_at')->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incentive_workflow_histories');
    }
};
