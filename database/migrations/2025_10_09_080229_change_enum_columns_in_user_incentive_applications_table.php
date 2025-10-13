<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `user_incentive_applications`
        MODIFY COLUMN `workflow_status` ENUM(
            'draft',
            'submitted',
            'approved_by_da',
            'rejected_by_da',
            'sent_back_by_da',
            'under_review_gm',
            'approved_by_gm',
            'rejected_by_gm',
            'sent_back_by_gm'
        ) NULL DEFAULT 'draft'");

        Schema::table('user_incentive_applications', function (Blueprint $table) {
            $table->string('claim_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `user_incentive_applications`
        MODIFY COLUMN `workflow_status` ENUM(
            'draft',
            'submitted',
            'under_review_da',
            'under_review_gm',
            'approved',
            'rejected',
            'sent_back'
        ) DEFAULT 'draft'");

        DB::statement("ALTER TABLE `user_incentive_applications`
        MODIFY COLUMN `claim_type` ENUM(
            'one_time',
            'monthly',
            'quarterly'
        ) NULL DEFAULT NULL");
    }
};
